# PX Shop Core — pokyny pre AI

Shop funkcie pre WooCommerce eshopy Pixeleru, nezávislé od vzhľadu. Repo je
**verejné** (`pixeler-sk/px-shop-core`) — do kódu, commitov ani dokumentácie
nepatria heslá, tokeny, interné cesty serverov ani údaje klientov.

Podrobnosti sú v [README.md](README.md) (moduly, filtre, konvencie pre tému)
a [RELEASING.md](RELEASING.md) (vydanie, konzumenti). Tento súbor je mapa.

## Vrstvy — kam čo patrí

| Vrstva | Čo tam patrí |
|---|---|
| **px-shop-core** (toto repo) | funkcie, ktoré použije **každý** eshop: dáta, REST, AJAX, admin, WP-CLI, e-maily, neutrálny markup `px-*` |
| `px-shop-theme` (parent téma, súkromné repo) | vzhľad: CSS, tokeny `--pc-*`, `.pc-scope` komponenty, WooCommerce šablóny |
| child téma klienta | vzhľad a doménové časti jedného webu |
| site plugin klienta | dáta a logika jedného klienta (importy, pneu/vozidlá, sklady…) |

- Jadro ostáva štíhle: funkcia jedného klienta sem nepatrí — patrí do jeho
  site pluginu, prípadne cez filter `px_shop_core_modules` ako vlastný modul.
- Plugin nekreslí dizajn. Markup je neutrálny `px-*`, štýly dodáva téma.
- Žiadna závislosť na Elementore ani Woodmarte.

## Nový modul

- Register v `includes/modules.php`, trieda `includes/class-px-<nazov>.php`.
- Vypnutý modul sa **vôbec nenačíta** (žiadne hooky, routy, CLI) —
  `class_exists( 'PX_Xyz' )` je pre tému test dostupnosti.
- `default` v registri je `'yes'`, ak sa neuvedie. Aktualizácia ide na
  všetky weby sama, takže modul, ktorý mení front, pokladňu alebo môže
  kolidovať s iným pluginom, daj `'default' => 'no'` (ako `consent`,
  `company_fields`, `media_abilities`) — zapne si ho web, ktorý ho chce.
- Sekcia v README.md (čo robí, nastavenia, filtre, hooky pre tému).
- Nikdy nečítať súhlas ani stav košíka pri výstupe, ktorý ide do page cache;
  inak `px_shop_core_no_page_cache()`.
- WCAG 2.1 AA, escapovanie výstupu, nonce + `current_user_can` pri zápise,
  `$wpdb->prepare`, i18n s textdomain `px-shop-core`, žiadne dotazy v cykle.
- Minimum: PHP 7.4, WordPress 6.0 (hlavička pluginu) — nepoužívať novší
  syntax, kým sa minimum nezdvihne.

## Kompatibilita s pluginmi webov

Pred novým modulom alebo vydaním si prečítaj
`~/localhost/px-shop-dev/COMPATIBILITY.md` (pluginy na weboch, známe kolízie,
postup); novú kolíziu tam zapíš. Modul, ktorý zdvojuje funkciu bežného
pluginu, má `'default' => 'no'` a pri bežiacom plugine sa nespustí.

## Testovanie

Libike, drogea a elbe majú lokálne `wp-content/plugins/px-shop-core` ako
symlink na toto repo — zmena je vidieť hneď. Pneuvosovic má nainštalovanú
kópiu z releasu (zmenu nevidí). Ak sa mení aj vzhľad, upravuje sa paralelne
`px-shop-theme` (spúšťaj session s `--add-dir ../px-shop-theme`).

## Vydanie (plne v RELEASING.md)

Pred vydaním v `~/localhost/px-shop-dev`: `bin/smoke.sh` (stránky, pokladňa, e-maily) a `bin/lint.sh px-shop-core` (PHPCS + PHPStan, konfigurácia v `phpcs.xml.dist` / `phpstan.neon.dist`).

1. Verzia na **troch** miestach: hlavička `px-shop-core.php`,
   `PX_SHOP_CORE_VERSION`, `Stable tag` v `readme.txt`.
2. Changelog v `readme.txt` presne ako `= X.Y.Z =`.
3. `git commit`, `git tag vX.Y.Z`, push vetvy aj tagu → GitHub Action zostaví
   zip a release.
4. Weby si aktualizáciu nájdu samé (Plugin Update Checker, raz za 12 h,
   verejné repo = bez tokenu). Pri zmene, ktorú potrebuje aj téma, vydaj
   najprv core, potom tému.
