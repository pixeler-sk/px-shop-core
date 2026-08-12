=== PX Shop Core ===
Contributors: pixeler
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.1
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
* **Waitlist** — back-in-stock subscriptions with double opt-in, unsubscribe link and four WooCommerce e-mails
* **Shipping bar** — free-shipping threshold helper for themes
* **Catalog mode** — optional "showroom" mode (prices/cart hidden), off by default
* **Attribute images** — image field on product attribute terms
* **Content items** — hidden CPT for reusable content blocks rendered by the theme
* **Size guides** — size tables in a modal, assigned per product or per product category

Every feature is a module that can be switched off in WooCommerce → Settings →
PX Shop. A module that is off is not loaded at all — no hooks, no REST routes,
no admin screens — so `class_exists( 'PX_Wishlist' )` stays the reliable test
for themes.

== Changelog ==

= 1.3.1 =

Živé hľadanie (`pixeler/v1/search`):

* Šepkávač hľadá to, čo obchod naozaj ukáže — pri zapnutom „skryť vypredané"
  (`woocommerce_hide_out_of_stock_items`) už neponúka produkty, ktoré
  vyhľadávacia stránka zahodí, a „Zobraziť všetkých N výsledkov" nesľubuje
  číslo, ktoré archív nedoručí.
* Kategórie s nulovým počtom viditeľných produktov vypadávajú — `hide_empty`
  ich nechytí, lebo WooCommerce prepisuje počty vlastnými až po dotaze.
  Odkaz na prázdny archív je slepá ulička.
* Položka nesie `in_stock` — témy si nedostupné označovali, server im to
  ale neposielal.

= 1.3.0 =

Moduly a nastavenia:

* Každá funkcia pluginu je teraz **modul, ktorý sa dá vypnúť** — WooCommerce →
  Nastavenia → **PX Shop** → Modules. Vypnutý modul sa vôbec nenačíta:
  neregistruje hooky, REST routy, admin obrazovky ani WP-CLI príkazy. Téme tak
  ostáva `class_exists( 'PX_Wishlist' )` ako spoľahlivý test dostupnosti
  funkcie a nepotrebuje žiadnu zmenu.
* **Existujúce weby sa nemenia.** Stav modulov je jedna option
  (`px_shop_core_modules`); kým ju nikto neuloží, platia defaulty z registra —
  a tie sú nastavené na dnešné správanie, teda všetko zapnuté.
* Uložené dáta zostávajú aj po vypnutí modulu (prihlásení na naskladnenie,
  obľúbené, GPSR polia) a po zapnutí sa vrátia.
* Stav modulu sa dá pripnúť v kóde filtrom `px_shop_core_module_on` — pri
  takom module to nastavenia napíšu, nech obrazovka netvrdí niečo iné, než
  web robí. Vlastný modul sa pridá filtrom `px_shop_core_modules` a dostane
  prepínač aj sekciu nastavení.
* **Nastavenia katalógového režimu sa presunuli** z Produkty → Katalógový
  režim do WooCommerce → Nastavenia → PX Shop → Katalógový režim. Názvy
  options sú nezmenené, takže hodnoty ostávajú.
* Wishlist a Compare majú konečne pole na výber stránky (`px_wishlist_page_id`,
  `px_compare_page_id`) — doteraz sa dali nastaviť len priamo v databáze.

= 1.2.0 =

Veľkostné tabuľky (nové):

* Nový typ obsahu `px_size_guide` (Produkty → Veľkostné tabuľky) s tabuľkou
  a voliteľným textom. Produkt si tabuľku vyberie sám, inak ju zdedí
  z kategórie produktov; hodnota „nezobrazovať" ju potlačí aj proti
  kategórii.
* Tabuľka sa edituje ako text — riadok na riadok, bunky oddelené znakom `|`.
  Funguje aj tabuľka vložená z tabuľkového procesora (tabulátory).
* Vykreslenie si vyberá téma: `PX_Size_Guide::render()` dá odkaz s modálnym
  oknom, `::get_content_html()` samotný obsah na vlastné miesto (napr. do
  produktového tabu) a `::has_guide()` povie, či vôbec je čo zobraziť. Témy,
  ktoré spúšťajú `woocommerce_single_product_summary`, dostanú modál samy.
* Prechodový most na Woodmart: kým dáta nie sú prevedené, produkt bez
  vlastného `_px_size_guide` číta `woodmart_sguide_select` a vykreslí starú
  príručku. Celý most je v jednom bloku triedy a po prevode sa zmaže.
* Prevod dát: `wp px size-guide migrate [--dry-run]` — idempotentný, prenesie
  príručky, priradenia na produktoch aj na kategóriách a pomenuje tie, ktoré
  nikto nepoužíva.

Waitlist (prepísaný na plnú paritu s Woodmartom):

* **Double opt-in** — adresa sa počíta až po kliknutí v e-maile.
  **Odhlásenie** jedným odkazom v každej správe. Záznam nesie aj `user_id`.
* Štyri e-maily ako bežné WooCommerce triedy — nastaviteľné vo
  WooCommerce → Nastavenia → E-maily, šablóny prepísateľné v téme
  (`woocommerce/emails/px-waitlist-*.php`): potvrdenie adresy, potvrdené
  prihlásenie, naskladnenie a notifikácia pre administrátora.
* Nové akcie na zavesenie vlastnej logiky: `px_waitlist_subscribed`,
  `px_waitlist_confirmed`, `px_waitlist_unsubscribed`,
  `px_waitlist_back_in_stock`.
* Hotový formulár `PX_Waitlist::render_form( $product )` pre témy, ktoré
  vlastný nemajú; REST endpoint `px-shop-core/v1/waitlist` ostáva.
* Odhlásenie maže adresu zo **všetkých** produktov. Globálny blocklist
  odhlásených adries, aký mal Woodmart, sa nepreberá — double opt-in už
  zaručuje, že adresu nikto cudzí nezapíše, a blocklist by len bránil
  neskoršiemu legitímnemu prihláseniu.
* Prevod dát: `wp px waitlist migrate [--dry-run]` z tabuľky
  `<prefix>woodmart_waitlists` (rešpektuje aj `variation_id`).
* Staršie záznamy vo formáte `email => timestamp` sa čítajú ako potvrdené,
  takže weby na verzii 1.0 nič nestratia.

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
