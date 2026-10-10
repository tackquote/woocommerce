# Vendored TackQuote widget catalogue

These files are a COPY of tack `packages/widget/locales/` (en, de, es, fr, it, ja, nl,
pt-BR and `machine-translated.json`) at commit

    8e8b11b1e4279f1aa764e74f57b354769262b4dd

(2026-10-10, "fix(widget): formal register for the es/nl/it parity keys"), taken from
`origin/main` with `git show <sha>:packages/widget/locales/<file>`. The plugin build reads
only this copy; it never reaches into a tack checkout, so a release builds the same way
anywhere.

To refresh: copy the files again from a newer tack commit, replace the sha above, run
`php bin/build-translations.php` and commit the regenerated `languages/` files with it.
Do not edit translations here; fix them in tack, where every storefront shares them.
