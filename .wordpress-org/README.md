# WordPress.org listing assets

Banners, icons and screenshots for the plugin's page in the WordPress.org
directory. **These are not part of the plugin.** wordpress.org serves them from
the `assets/` folder of the plugin's SVN repository, separately from the code, so
they must never be inside `tackquote.zip` — every byte that ships there is
downloaded by every user on every install and update, for images they will never
see in their admin.

`bin/build.sh` excludes this directory and then asserts the exclusion worked, so
a leak fails the build rather than quietly producing a fat zip.

## What is here

| File | Where it appears |
|---|---|
| `banner-1544x500.png`, `banner-772x250.png` | Header of the directory page (retina and standard) |
| `icon-128x128.png`, `icon-256x256.png` | Search results and the plugin card |
| `screenshot-1.png` … `screenshot-16.png` | The Screenshots tab, captioned **in numeric order** by the `== Screenshots ==` list in `readme.txt`; 1280x800 PNG, one per feature (also shown in the repository README) |

## The one rule that is easy to break

The captions in `readme.txt` are matched to these files **by number, not by
name**. Renumbering a screenshot without editing that list silently re-captions
every screenshot after it. If you add, remove or reorder one, update
`== Screenshots ==` in the same change.

Screenshots 13 to 15 show the settings screen and 14 and 16 the styling options,
so they go stale whenever those screens change. Screenshots 4, 5, 7, 9 and 11 are
two views placed side by side or stacked on one 1280x800 canvas.

## How the screenshots were made

WordPress Studio with demo products ("Demo ..." names, drawn product images, no
personal data), Chrome at a 1280x800 viewport (2x then scaled for 9 and 11).
The site's TackQuote API was blocked for the whole run by a temporary mu-plugin
that answered with demo fixtures (prices, buyer group, order limits, net-terms
standing, wholesale form, quote checkout) and refused every other call, so no
request reached a TackQuote server. Icons and banners are regenerated from
`tack/apps/web/public/brand/tackquote-app-icon.png` (the current mark).

Superseded files were removed on 2026-10-10 (owner request): the pre-redesign `demo/` captures and the old source marks. Icons and banners are regenerated from the brand kit in the tack repo (`apps/web/public/brand/tackquote-app-icon.png`); screenshots come from the Studio site on the current design.
