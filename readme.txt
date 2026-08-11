=== PX Shop Core ===
Contributors: pixeler
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce extensions shared across Pixeler shop projects. Presentation lives in the theme; this plugin only provides functionality.

== Description ==

Reusable WooCommerce shop features. Markup is neutral (px-* classes) and
minimal — styling and page-level presentation belong to the active theme.

* **Omnibus** — EU "lowest price in the last 30 days" for discounted products, with automatic price history
* **GPSR** — product safety fields (manufacturer, EU responsible person, origin, warnings, docs) with an admin tab and a frontend product tab
* **Live search** — REST endpoint `pixeler/v1/search` (products + matching categories)
* **Brand tab** — "About the brand" product tab from `product_brand` term description + logo
* **Wishlist** — cookie/user-meta storage, REST `px-shop-core/v1/wishlist`, `[px_wishlist]` shortcode
* **Compare** — cookie storage, REST endpoints, `[px_compare]` shortcode
* **Waitlist** — back-in-stock e-mail subscriptions per product
* **Shipping bar** — free-shipping threshold helper for themes
* **Catalog mode** — optional "showroom" mode (prices/cart hidden), off by default
* **Attribute images** — image field on product attribute terms
* **Content items** — hidden CPT for reusable content blocks rendered by the theme

== Changelog ==

= 1.1.0 =
* GPSR: údaje o výrobcovi a zodpovednej osobe v EÚ sa vypĺňajú na termíne
  značky (Produkty → Značky), nie na každom produkte zvlášť.
* Každý údaj má práve jedno miesto, bez výnimiek: polia značky sa na
  produkte ukazujú len na čítanie aj s odkazom na značku a produktové meta
  sa pri nich nečíta ani neukladá. Produkt s iným výrobcom dostane inú
  značku.
* Krajina pôvodu, bezpečnostné upozornenia a dokumentácia ostávajú na
  produkte — tie sa kus od kusu naozaj líšia.
* Sada polí je filterovateľná cez `px_gpsr_fields`, výber polí značky cez
  `px_gpsr_entity_fields`.
* Omnibus: nový filter `px_omnibus_display` vypne výpis najnižšej ceny bez
  toho, aby prestala vznikať história — web, ktorý cenu zobrazuje iným
  pluginom, tak môže neskôr prejsť na náš výpis bez diery v dátach.
* Obrázky termov atribútov: `px_get_term_image_id()` a `px_get_term_image()`
  sú chránené pred redeklaráciou (staršie weby nesú rovnomennú funkciu
  v mu-plugine px-core, čo končilo fatal errorom). Bez rizika kolízie sa dá
  volať `PX_Attribute_Image::image_id()` a `::image()`.

= 1.0.0 =
* Prvé vydanie z verejného repozitára s automatickými aktualizáciami
  (Plugin Update Checker + GitHub releases).
* Moduly: Omnibus, GPSR, live search, brand tab, wishlist, compare,
  waitlist, shipping bar, catalog mode, attribute images, content items.
* Slovenské preklady.
