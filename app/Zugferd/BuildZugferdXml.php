<?php

declare(strict_types=1);

namespace App\Zugferd;

use App\Documents\BuyerAddress;
use App\Documents\SellerIdentity;
use App\Models\Document;
use Brick\Money\Money;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use LogicException;

/**
 * The Beleg as EN16931 XML (system design §7).
 *
 * ZUGFeRD / Factur-X only: one PDF/A-3 file that is both human-readable and
 * machine-readable. No bare XML output and no XRechnung — there is no B2G in
 * scope, so no Leitweg-ID and no Peppol.
 *
 * Everything about the two parties is read from the **Festschreibung**, never
 * from the live company or customer. That is what makes the embedded XML and
 * the printed page describe the same invoice: if the XML read the live models
 * and the page read the frozen block, a company that moved between issuing and
 * a later reprint would produce a file that contradicts itself.
 *
 * Neither the compliance metadata nor the XML structure is written here — the
 * library owns both. What is written here is the mapping from this domain to
 * the EN16931 business terms, and the BT numbers are named in the comments
 * because they are the only way to check the mapping against the standard.
 */
final class BuildZugferdXml
{
    /**
     * UNTDID 1001 code for a commercial invoice. A Gutschrift will carry 389
     * instead and swap the two parties; a Storno stays 380 with negative
     * figures. Neither exists yet, which is why this is not a match on the
     * Belegart.
     */
    private const string INVOICE_TYPE = ZugferdInvoiceType::INVOICE;

    public function __invoke(Document $document): ZugferdDocumentBuilder
    {
        $block = $document->frozen_block;

        throw_if(
            $block === null || $document->number === null || $document->due_on === null,
            LogicException::class,
            'A Beleg can only be expressed as EN16931 once it carries its Belegnummer, its Festschreibung and its Fälligkeitsdatum.',
        );

        $seller = $block->seller;
        $buyer = $block->buyer;
        $totals = $document->totals();

        $builder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        // BT-1 Belegnummer, BT-3 type code, BT-2 Ausstellungsdatum, BT-5 currency.
        $builder->setDocumentInformation(
            $document->number,
            self::INVOICE_TYPE,
            $document->issued_on->toDateTime(),
            'EUR',
        );

        $this->seller($builder, $seller);
        $this->buyer($builder, $block->buyer);

        // BT-72 or BG-14, never both — the model guarantees exactly one is set,
        // and EN16931 has no structure that holds both.
        if ($document->performed_on !== null) {
            $builder->setDocumentSupplyChainEvent($document->performed_on->toDateTime());
        } elseif ($document->performed_from !== null && $document->performed_to !== null) {
            $builder->setDocumentBillingPeriod(
                $document->performed_from->toDateTime(),
                $document->performed_to->toDateTime(),
                null,
            );

            // BT-80, the country of delivery. Factur-X rule BR-FX-EN-04 asks
            // for it whenever BT-72 is absent — a Beleg over a Leistungszeitraum
            // names no single delivery day, so the country is what remains to
            // place the supply. Setting it also fills
            // ApplicableHeaderTradeDelivery, which would otherwise be emitted
            // empty and trip PEPPOL-EN16931-R008.
            //
            // The buyer's country, because that is where the service was
            // received, and because it is DE for the same reason every other
            // country here is (see FrozenBlock::COUNTRY).
            //
            // The party has to be named before the address: an address with no
            // party to hang on leaves ApplicableHeaderTradeDelivery empty, which
            // is how this was first written and what the Schematron caught.
            $builder->setDocumentShipTo($buyer->name);
            $builder->setDocumentShipToAddress(country: $buyer->country);
        }

        // BT-9 Fälligkeitsdatum and BT-20 the Zahlungsziel in words. BR-CO-25
        // requires one of the two whenever anything is payable; both are given
        // because both print on the page.
        $builder->addDocumentPaymentTerm(
            $document->payment_term->getLabel(),
            $document->due_on->toDateTime(),
        );

        // BG-16/BG-17: SEPA credit transfer to the company's IBAN. Only when
        // there is one — CheckReadiness merely warns about missing bank
        // details, because an invoice without an IBAN is valid and awkward to
        // pay rather than invalid.
        if ($seller->iban !== null) {
            $builder->addDocumentPaymentMeanToCreditTransfer(
                $seller->iban,
                $seller->bankName,
                null,
                $seller->bic,
                $document->number,
            );
        }

        $this->positions($builder, $document);
        $this->taxGroups($builder, $document, $seller);

        // BG-22. Each figure comes from CalculateTotals rather than being
        // re-added here, so the XML cannot disagree with the page by a cent.
        // There are no Zu- or Abschläge, so the Bemessungsgrundlage equals the
        // line total and the amount due equals the Bruttobetrag.
        $builder->setDocumentSummation(
            grandTotalAmount: $this->amount($totals->gross),
            duePayableAmount: $this->amount($totals->gross),
            lineTotalAmount: $this->amount($totals->net),
            chargeTotalAmount: 0.0,
            allowanceTotalAmount: 0.0,
            taxBasisTotalAmount: $this->amount($totals->net),
            taxTotalAmount: $this->amount($totals->tax),
        );

        return $builder;
    }

    private function seller(ZugferdDocumentBuilder $builder, SellerIdentity $seller): void
    {
        // BT-27 the name as it legally reads, which for a GmbH includes the
        // designation (§35a GmbHG).
        $builder->setDocumentSeller($seller->legalName());
        // BG-5, with BT-40 the country code.
        $builder->setDocumentSellerAddress(
            lineOne: $seller->street,
            postCode: $seller->postalCode,
            city: $seller->city,
            country: $seller->country,
        );

        // BT-30: the Handelsregister entry, which is what §35a GmbHG makes
        // compulsory and what the recipient's software shows as the legal
        // registration.
        if ($seller->registerNumber !== null) {
            // **No schemeID.** BT-30-1 takes an ISO 6523 ICD, and there is no
            // ICD for a German Handelsregister — the list covers SIRENE,
            // DUNS, GLN, LEI and a handful of national registers, none of them
            // ours. This first carried `0002`, which is French SIRENE: a
            // recipient reading it literally would take „HRB 123456" for a
            // SIREN. The Schematron does not object, because 0002 is a real
            // code; it is simply the wrong fact. Left off, the number is what
            // it is — an identifier the reader displays and does not parse.
            $builder->setDocumentSellerLegalOrganisation(
                $seller->registerNumber,
                null,
                $seller->legalName(),
            );
        }

        // BT-31 is the USt-IdNr. and BT-32 the Steuernummer. A German company
        // states one or the other, which is exactly what CheckReadiness blocks
        // on, so both are offered and neither is assumed.
        if ($seller->vatId !== null) {
            $builder->addDocumentSellerTaxRegistration('VA', $seller->vatId);
        }

        if ($seller->taxNumber !== null) {
            $builder->addDocumentSellerTaxRegistration('FC', $seller->taxNumber);
        }
    }

    private function buyer(ZugferdDocumentBuilder $builder, BuyerAddress $buyer): void
    {
        // BT-44 the name, BT-46 the Kundennummer the customer quotes back.
        $builder->setDocumentBuyer($buyer->name, $buyer->number);
        // BG-8, with BT-55 the country code.
        $builder->setDocumentBuyerAddress(
            lineOne: $buyer->street,
            postCode: $buyer->postalCode,
            city: $buyer->city,
            country: $buyer->country,
        );

        if ($buyer->contactPerson !== null || $buyer->email !== null) {
            $builder->setDocumentBuyerContact($buyer->contactPerson, null, null, null, $buyer->email);
        }

        // BT-48, the customer's own USt-IdNr., which a Geschäftskunde may have
        // and a Privatkunde never does.
        if ($buyer->vatId !== null) {
            $builder->addDocumentBuyerTaxRegistration('VA', $buyer->vatId);
        }
    }

    private function positions(ZugferdDocumentBuilder $builder, Document $document): void
    {
        $seller = $document->frozen_block?->seller;

        foreach ($document->lineItems as $item) {
            // BT-126 the line identifier, which is the printed Pos.
            $builder->addNewPosition((string) $item->position);
            // BT-153 name and BT-154 description.
            $builder->setDocumentPositionProductDetails($item->title, $item->description);
            // BT-146 the Einzelpreis.
            $builder->setDocumentPositionNetPrice($this->amount($item->unit_price));
            // BT-129 quantity and BT-130 its UN/ECE code — which the Unit enum
            // is backed by, so there is nothing to look up (ADR 0003).
            $builder->setDocumentPositionQuantity((float) $item->quantity, $item->unit->value);
            // BT-151 category and BT-152 rate. Per Position, because a Beleg
            // may mix 19 % and 7 %.
            $builder->addDocumentPositionTax(
                $this->categoryFor($item->tax_rate, $seller),
                'VAT',
                $this->percent($item->tax_rate),
            );
            // BT-131, the same figure the page prints for the line.
            $builder->setDocumentPositionLineSummation($this->amount($item->net()));
        }
    }

    private function taxGroups(ZugferdDocumentBuilder $builder, Document $document, SellerIdentity $seller): void
    {
        // BG-23, one per Steuersatz. These are CalculateTotals' groups, rounded
        // once each per §6 — which is what makes the PDF agree to the cent with
        // the recipient's accounting software.
        foreach ($document->totals()->groups as $group) {
            $builder->addDocumentTax(
                categoryCode: $this->categoryFor($group->rate, $seller),
                typeCode: 'VAT',
                basisAmount: $this->amount($group->base),
                calculatedAmount: $this->amount($group->tax),
                rateApplicablePercent: $this->percent($group->rate),
                // BT-120: a zero-rated group must say why it is zero-rated.
                exemptionReason: $this->exemptionReason($group->rate, $seller),
            );
        }
    }

    /**
     * BT-151 / BT-118, the UNTDID 5305 category.
     *
     * `S` is the standard rate. A 0 % group is `E` — exempt — when the company
     * invoices under §19 UStG, because that is a genuine exemption with a
     * reason the recipient has to be told; a 0 % rate under normal taxation is
     * `Z`, zero-rated.
     */
    private function categoryFor(int $basisPoints, ?SellerIdentity $seller): string
    {
        if ($basisPoints > 0) {
            return 'S';
        }

        return $seller?->isSmallBusiness() === true ? 'E' : 'Z';
    }

    private function exemptionReason(int $basisPoints, SellerIdentity $seller): ?string
    {
        if ($basisPoints > 0 || ! $seller->isSmallBusiness()) {
            return null;
        }

        return __('document.pdf.small_business_note');
    }

    /**
     * Basis points to the percentage EN16931 states: 1900 → 19.0.
     */
    private function percent(int $basisPoints): float
    {
        return $basisPoints / 100;
    }

    /**
     * The library's boundary takes floats, so this is where money stops being
     * exact — and the only place it does.
     *
     * Converting from the decimal string rather than from the minor amount: a
     * two-decimal value in the range an invoice reaches is exactly
     * representable, so the float carries the same cents the integer did. The
     * arithmetic has already happened in `brick/money` by this point; nothing
     * is computed from these floats, they are only serialised.
     */
    private function amount(Money $money): float
    {
        return (float) (string) $money->getAmount();
    }
}
