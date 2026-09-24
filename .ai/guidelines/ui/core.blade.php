# Interface conventions

These are settled. They are not defaults to be improved on, and a screen that
breaks one needs a reason stated out loud, not a preference.

## Row actions live in a dropdown

**A table row gets one action control: a vertical-ellipsis (⋮) button that opens
a dropdown containing the actions.** Never a row of buttons, never icons strung
across the row, never an action column that widens with each feature.

In Filament 5, this is `ActionGroup` — the default trigger is already the
vertical ellipsis, so wrap the row's actions in one rather than returning them
loose:

```php
->recordActions([
    ActionGroup::make([
        ViewAction::make(),
        EditAction::make(),
        DeleteAction::make(),
    ]),
])
```

The reason is that loose row actions are a ratchet. Every feature adds one, no
feature ever removes one, and the table ends up wider than the data it exists to
show — with the destructive action sitting a few pixels from the common one.

## Simplicity over completeness

**When a screen could show more or show less, show less.** Density is not a
feature, and "while we're here" is how a form grows to forty fields nobody reads.

In practice:

- Put on the screen what the task needs. Everything else goes behind a link, a
  detail view, or a collapsed section — not in a sidebar "for convenience".
- Prefer one obvious primary action to three equal ones. If everything is
  emphasised, nothing is.
- Do not add a filter, a widget, a badge or a column speculatively. Add it when
  a real task needs it.
- Empty states say what to do next, rather than apologising for being empty.

When a choice is genuinely balanced, take the plainer one. It is much cheaper to
add an affordance someone asked for than to remove one they have started relying
on.
