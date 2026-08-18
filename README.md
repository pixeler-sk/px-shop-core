# PX Shop Core

Zdieľané WooCommerce funkcie pre Pixeler eshopy. Prezentácia žije v téme —
tento plugin dodáva len funkcionalitu (neutrálny `px-*` markup, dáta, REST).

Moduly: Omnibus (najnižšia cena za 30 dní), GPSR, firemné údaje
(IČO/DIČ/IČ DPH, RPO/ARES, VIES, reverse charge), live search, brand tab,
wishlist, compare, waitlist, veľkostné tabuľky, súvisiace kategórie,
shipping bar, katalógový režim, obrázky atribútov, content bloky,
Google Consent Mode v2.
Podrobnosti v [readme.txt](readme.txt).

Každý modul sa dá vypnúť vo **WooCommerce → Nastavenia → PX Shop**. Vypnutý
modul sa vôbec nenačíta — neregistruje hooky, REST routy ani admin obrazovky,
takže `class_exists( 'PX_Wishlist' )` ostáva pre tému spoľahlivým testom
dostupnosti. Register je v [includes/modules.php](includes/modules.php):
filter `px_shop_core_modules` pridá vlastný modul, `px_shop_core_module_on`
pripne stav v kóde (weby vedené cez git).

- **Inštalácia a aktualizácie:** [RELEASING.md](RELEASING.md) — releases cez
  tagy, weby sa aktualizujú cez Plugin Update Checker.
- Vyžaduje WooCommerce; WordPress ≥ 6.0, PHP ≥ 7.4.
- Licencia: GPL v2 or later.
