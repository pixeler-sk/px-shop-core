=== PX Shop Core ===
Contributors: pixeler
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce extensions shared across Pixeler shop projects. Presentation lives in the theme; this plugin only provides functionality.

== Description ==

Reusable WooCommerce shop features. Markup is neutral (px-* classes) and
minimal — styling and page-level presentation belong to the active theme.

* **Omnibus** — EU "lowest price in the last 30 days" for discounted products, with automatic price history
* **Sale validity** — until when a discounted price applies, as a date or a countdown near the end
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
* **Related categories** — accessories set once per category (bicycles → lights, bottle cages) instead of product by product
* **Company details** — IČO/DIČ/IČ DPH on the checkout, filled from RPO/ARES, VAT id verified in VIES, EU reverse charge and export outside the EU (off by default)
* **Cookie consent** — the shop's own consent banner (services, blocking, cookie policy page, Google Consent Mode v2), off by default; replaces an external CMP, never runs next to one
* **Google Consent Mode v2** — sends Google the consent signals the free Complianz build cannot; Complianz stays the CMP (off by default, needs Complianz)

Every feature is a module that can be switched off in WooCommerce → Settings →
PX Shop. A module that is off is not loaded at all — no hooks, no REST routes,
no admin screens — so `class_exists( 'PX_Wishlist' )` stays the reliable test
for themes.

== Changelog ==

= 1.8.0 =

Kompatibilita s page cache (overené na WP Rocket 3.23.3.2). Plugin cache nemá
a mať nebude, ale kreslí veci, ktoré sa cachovať nesmú, a mení obsah, o ktorom
cache nevie. Všetky volania WP Rocketu sú cez `function_exists()`, takže na
webe bez neho sa nemení nič.

* **Obľúbené a porovnanie:** shortcody `[px_wishlist]` a `[px_compare]` stavajú
  mriežku v PHP z cookie návštevníka, ale stránku z cache nevynímali. Do page
  cache sa tak zapiekol zoznam prvého návštevníka a dostali ho všetci ostatní —
  cudzie položky vo vlastnom zozname a únik toho, čo si pozeral niekto iný. Na
  weboch s px-shop-theme to prebíjala téma vlastným shortcodom, ktorý ochranu
  má; teraz je `DONOTCACHEPAGE` + `nocache_headers()` priamo v jadre, takže je
  to bezpečné aj na holej téme. Prázdny zoznam nie je o nič bezpečnejší, preto
  ochrana beží ešte pred ním.

* **Bannery a veľkostné tabuľky:** `px_content` aj `px_size_guide` sú neverejné
  post typy, na ktorých `rocket_clean_post()` bailne — klient upravil banner,
  uložil a na webe sa nezmenilo nič, ani na úvodke. Uloženie, presun do koša,
  obnova aj zmazanie teraz purgujú cache celej domény; banner nemá vlastnú URL
  a môže byť naraz na úvodke, v kategórii aj na detaile, takže jemnejší purge
  nemá zmysel. Purguje aj priradenie tabuľky ku kategórii produktov. Purge sa
  odkladá na koniec requestu a spraví sa raz, aby import sto bannerov
  neznamenal sto mazaní cache; počas importu (`WP_IMPORTING`,
  `rocket_is_importing()`) sa vynecháva úplne — WP All Import beží po dávkach
  a každá dávka je vlastný request, takže poistka „raz za request" by cache
  držala trvalo prázdnu. Cache po importe čistí site plugin webu. Used CSS sa
  zámerne nemaže.

* **Nové rozšírenie pre ostatné cache:** akcia `px_shop_core_purge_page_cache`
  (LiteSpeed, Varnish, Cloudflare si web dovesí v site plugine) a verejné
  funkcie `px_shop_core_no_page_cache()` a `px_shop_core_purge_page_cache()`
  pre témy a site pluginy.

* **Live search:** šepkávač posielal `Cache-Control: public` aj prihlásenému,
  hoci odpoveď nesie cenu ako hotové HTML a prihlásený môže mať vlastnú cenovú
  hladinu. Cez Cloudflare alebo inú zdieľanú proxy sa tak dala jeho cena podať
  anonymnému návštevníkovi. Prihlásený (a hosť oslobodený od DPH) dostáva
  `private, no-store`; pre ostatných ostáva `public, max-age=60`, ale už
  s `Vary: Accept-Language, Cookie` — tým istým, čím sa líši serverový kľúč
  (jazyk, mena, daňový kontext), sa teraz musí líšiť aj záznam na proxy.

* **Omnibus — spoľahlivý zber histórie.** Doteraz sa história dopĺňala „pri
  zobrazení detailu", čo pod page cache takmer nebeží: PHP sa spustí len pri
  cache miss a diery boli práve tam, kde vadia — na začiatku a konci akcie.
  Najnižšia cena za 30 dní je právne tvrdenie, nie kozmetika. Pribudol denný
  cron `px_omnibus_scan` (03:20 miestneho času), ktorý prejde všetko v zľave
  a dopíše chýbajúce záznamy. Zápis pri zobrazení ostáva ako záchrana.

* **Omnibus — jedna cenová báza.** História sa teraz zapisuje z
  `get_price( 'edit' )`, teda z ceny uloženej na produkte, bez filtrov na
  `woocommerce_product_get_price`. Dovtedy to bola lotéria: plugin plošných
  zliav (Global Shop Discount a spol.) sa na ten filter vešia s prioritou
  `PHP_INT_MAX` vždy, keď platí `! is_admin() || DOING_AJAX` — uloženie
  v administrácii teda zľavnené nebolo, ale zobrazenie na fronte aj request
  WP-Cronu áno (`wp-cron.php` nie je admin). V jednej histórii sa tak
  striedali dve neporovnateľné čísla a `get_lowest_price()` nad nimi počítal
  nezmysel. `'edit'` je reprodukovateľné a je to správny predmet tvrdenia:
  30-dňové minimum hovorí o cene produktu, nie o ohlásenej plošnej kampani,
  ktorá je samostatný konštrukt. Web, ktorý to vidí inak, mení to, čo sa
  **zobrazuje** (`px_omnibus_lowest_price`), nie to, čo sa zapisuje.

* **Omnibus — rovnaká báza aj pri čítaní.** `get_lowest_price()` porovnávala
  históriu s `get_price()` vo „view" kontexte, teda s cenou po plošnej zľave.
  Na webe s aktívnou plošnou kampaňou sa tak nezhodovala so žiadnym záznamom
  a výpočet „odkedy platí súčasná cena" spadol na „od teraz" pri každom
  produkte — do 30-dňového minima sa tým započítala aj práve prebiehajúca
  akcia. Číta sa `get_price( 'edit' )` a `get_regular_price( 'edit' )`.
  **Na weboch s plošnou zľavou sa tým mení zobrazená suma** — po nasadení
  treba pár zľavnených produktov skontrolovať.

* **Omnibus — koniec akcie sa zachytí aj bez dátumov.** Obchod, kde zľavy robí
  import, nemá `_sale_price_dates_*` ani jeden: importér jednoducho zmaže
  `_sale_price`, produkt vypadne z `onsale` a nevystrelí žiadny hook — pritom
  návrat na bežnú cenu je presne ten záznam, ktorý história potrebuje. Sken si
  preto zoznam ID z posledného behu pamätá v opcii `px_omnibus_scan_seen`
  (bez autoloadu) a ďalší beh prejde aj to, čo medzitým zo zľavy vypadlo.

* **Omnibus — čo sken NEberie.** Variabilných a zoskupených rodičov: nemajú
  vlastnú cenu, len odvodený rozsah, a WooCommerce im ukladá **viac riadkov**
  `_price` naraz (`add_post_meta` v cykle nad zoradenými cenami), takže
  „prečítať ich cenu" v SQL je hod mincou medzi najnižšou a najvyššou. Zobrazuje
  ich aj tak nikto — `render_single()` variabilné preskakuje a
  `append_to_variation()` číta variáciu. Z rovnakého dôvodu ich preskakuje aj
  `record()`. Ďalej koncepty a kôš (aj variácie, ktorým rodič skončil v koši —
  tie si `post_status` `publish` nechávajú).

* **Omnibus — prevádzka skenu.** Beží po dávkach po 200, jeden dotaz na dávku,
  bez načítania produktových objektov; zapisuje len tam, kde sa cena naozaj
  pohla (na libike ~4 300 produktov za desatiny sekundy a nula zápisov, keď sa
  nič nezmenilo). Bez stropu — strop by rezal zoznam každú noc rovnako a tie
  isté produkty na konci katalógu by záznam nedostali nikdy. Filter
  `px_omnibus_scan_limit` (0 = bez stropu) sa aplikuje na hotový zlúčený
  zoznam, nie na jednotlivé dotazy; `px_omnibus_scan_ids` dostáva druhým
  parametrom podmnožinu, ktorá je práve v zľave. Sken **nevisí** na
  `woocommerce_scheduled_sales` — to je akcia Action Schedulera a plný prechod
  katalógu v cudzej fronte by po prekročení `action_scheduler_failure_period`
  (300 s) označil hostiteľskú akciu za zlyhanú. Presné ID naopak berie
  z `wc_after_products_starting_sales` / `wc_after_products_ending_sales`
  a `wc_product_start_scheduled_sale` / `wc_product_end_scheduled_sale`; sú to
  bonusy, nie mechanizmus — obchod bez dátumov akcie ich nevystrelí nikdy.

* **Waitlist:** e-mail prihláseného sa predvypĺňal priamo do HTML produktovej
  stránky — dnes bezpečné len preto, že prihlásení page cache nedostávajú. Ak
  sa taká adresa vypíše, stránka sa vyníma z cache; web, ktorý chce radšej
  cache, prefill vypne filtrom `px_waitlist_prefill_email`.

* **Cron a moduly:** definícia modulu smie deklarovať kľúč `cron`. Vypnutý
  modul si svoju naplánovanú udalosť upratuje sám (kontrola beží len
  v administrácii) a deaktivácia pluginu ju zruší tiež — žiadna osirelá
  udalosť, ktorú by WP-Cron plánoval donekonečna. Chýbajúce WooCommerce
  dôvodom na zrušenie **nie je**: jedno načítanie wp-adminu počas aktualizácie
  WooCommerce by inak zhodilo `px_omnibus_scan` a ten by sa naplánoval až na
  zajtra — ticho stratený deň zberu compliance dát.

= 1.7.0 =

* **Live search:** vyhľadávanie podľa **SKU a EAN/GTIN** — v šepkávači aj na
  štandardnej stránke výsledkov (`?s=…&post_type=product`). WordPress prehľadáva
  len názov, perex a obsah, takže nalepený kód dielu alebo pípnutý čiarový kód
  dovtedy nevrátil nič — jediný prípad, keď zákazník aj personál presne vedia,
  čo chcú. Zhoda variácie sa mapuje na rodiča (variácia nemá vlastnú stránku
  a na krabici býva jej SKU). Presná zhoda potláča prefixové: keď kód nesie
  konkrétny produkt, rodina s rovnakým začiatkom SKU je už len šum. Presná SKU
  alebo EAN tak vráti jeden výsledok a pri jednom výsledku presmeruje
  WooCommerce (`wc_template_redirect()`) rovno na produkt — ale len keď je
  v URL `post_type=product`, teda z formulára v hlavičke.

* **Live search:** SKU sa okrem presnej zhody hľadá aj podľa predpony, ale až
  od štyroch znakov, aby „RAM" neťahalo pol katalógu. Prefixové zhody sa radia
  podľa dĺžky SKU, takže keď ich je viac než strop, prežije to najbližšie
  napísanému — rez nesmie padnúť podľa ID produktu, päťdesiat najstarších
  z päťsto neodpovedá na nič. EAN/GTIN len presne a len v tvare
  GTIN-8/12/13/14. Dáta idú z `wc_product_meta_lookup` (riadok na produkt,
  ten istý zdroj ako vyhľadávanie v administrácii WooCommerce), pričom SKU
  a EAN sa pýtajú dvoma dotazmi: index má len `sku`, spoločné `OR` by oň
  optimalizátor pripravil a každá číselná fráza by znamenala full scan.

* **Live search:** lookup je cache, ktorú WooCommerce prepisuje pri uložení
  produktu, takže importér zapisujúci `_sku` priamo do postmeta ju vie nechať
  pozadu. Správna oprava je WooCommerce → Stav → Nástroje → *Obnoviť
  vyhľadávacie tabuľky produktov*. Dohľadanie priamo v postmeta (sken bez
  indexu) v kóde ostáva, ale beží len pod WP-CLI — verejné vyhľadávacie pole
  ho spúšťať nesmie, crawler s vymyslenými kódmi by ho vyvolal na každý
  request.

* **Live search:** nové filtre `px_shop_core_search_code_ids( $ids, $term,
  $limit )` (obchod s číslami dielov inde než v `_sku` si sem zapojí vlastný
  zdroj) a `px_shop_core_search_code_limit` (50 — strop zhôd na kód pridaných
  na stránku výsledkov). Helper `PX_Search::product_ids_matching_code( $term,
  $limit )` je verejný. Šepkávač nájdené produkty radí pred fulltextové
  výsledky; viditeľnosť, sklad a stav produktu rieši `WP_Query`, takže sa
  nefiltruje dvakrát. Na stránke výsledkov sa ID pripájajú do klauzuly
  `posts_search` — a keď fragment nevyzerá presne tak, ako ho stavia jadro
  (alebo v ňom po odtrhnutí ostane zmienka o `post_password`), filter dotaz
  nechá na pokoji; polovične pochopený WHERE je presne to, čím sa vo
  vyhľadávaní začnú objavovať koncepty.

* **Live search:** odpoveď šepkávača je 60 sekúnd v transiente a nesie
  `Cache-Control: public, max-age=60`. Endpoint `pixeler/v1/search` je verejný
  a bez rate limitu — každé písmeno v poli posielalo vlastný `WP_Query` +
  `get_terms()`, takže opakovaná fráza (aj cudzia) vedela zbytočne zaťažiť
  databázu. Filter `px_shop_core_search_response` beží aj nad cachovanými
  dátami, aby si téma vedela dopísať jazykovo závislé popisky.

* **Live search:** kľúč cache nesie normalizovanú frázu (malé písmená, zúžené
  medzery — „Prilba" a „prilba" je tá istá otázka), jazyk a všetko, od čoho
  závisí `price_html`: menu, nastavenie „ceny s daňou / bez dane" a oslobodenie
  od DPH, ktoré prepína aj modul firemných údajov tohto pluginu. Inak by prvý
  návštevník s iným daňovým kontextom otrávil ceny všetkým na 60 sekúnd.
  Prihlásenému zákazníkovi sa necachuje vôbec a zapisuje sa len s perzistentnou
  object cache — bez nej by každá neznáma fráza znamenala dva riadky vo
  `wp_options` a crawler by ich narobil státisíce. Na webe bez object cache tak
  serverová cache šepkávača nie je a zostáva len hlavička `Cache-Control`, ktorá
  opakované písmená zachytí v prehliadači a na CDN. Fráza je obmedzená na 64
  znakov, `viewAll` ostáva mimo cache (nesie pôvodné písanie návštevníka).

* **Live search:** nový filter `px_shop_core_search_query_args( $args, $term )`
  nad WP_Query argumentmi šepkávača. Web si tak vie preložiť časť frázy na
  atribútový filter — „matrac 90x200" na `tax_query` nad `pa_rozmer` plus
  fulltext „matrac" — bez toho, aby plugin poznal názvy atribútov konkrétneho
  e-shopu. Ak filter skráti `$args['s']`, vyhľadávanie kategórií použije
  skrátenú frázu (a pri prázdnej ho preskočí), takže spotrebované tokeny
  neprepadnú do zhody na názov kategórie.

= 1.6.0 =

Nový modul **Súhlas s cookies** (`consent`), predvolene vypnutý — vlastná CMP
namiesto Complianzu:

* **Register služieb je jediný zdroj pravdy.** Jedna deklarácia v PHP (názov,
  prevádzkovateľ, kategória, účel, odkaz na zásady, zoznam cookies, spôsob
  blokovania) živí lištu, modál, stránku so zásadami aj blokovanie. Vstavaný
  katalóg: GA4, Google Ads, Google Tag Manager, Meta Pixel, Microsoft Clarity,
  Smartsupp, Heureka Overené zákazníkmi, YouTube, Google Maps, reCAPTCHA plus
  vlastné cookies WordPressu, WooCommerce a PX Shop. Filter
  `px_consent_services` pridá službu site pluginu a tá dostane riadok v modále,
  na podstránke aj blokovanie zadarmo.
* **Lišta v dvoch vrstvách.** V prvej *Prijať všetko / Odmietnuť všetko /
  Nastavenia* — rovnaké tlačidlá rovnakej váhy, žiadne predznačené políčka,
  žiadna cookie wall a nič neblokuje obsah stránky. V druhej prepínače per
  kategória aj per službu s účelom, prevádzkovateľom a tabuľkou cookies.
  `role="dialog"`, focus trap, ovládanie klávesnicou, Esc zatvára modál.
* **Súhlas žije v cookie `px_consent`** (verzia zásad, čas, ID súhlasu,
  kategórie aj služby) s platnosťou 182 dní (`px_consent_lifetime_days`).
  Zmena poľa **Verzia zásad** v nastaveniach = lišta sa spýta znova všetkých.
  PHP cookie nikdy nečíta — výstup stránky je pre všetkých rovnaký a plnostránková
  cache nemá čo pokaziť.
* **Blokovanie:** skripty služieb idú do stránky ako
  `<script type="text/plain" data-px-consent="kategória" data-px-service="id">`
  a JS z nich po súhlase urobí skutočné skripty. Rovnaký kontrakt môže použiť
  ktorýkoľvek ručne vložený skript tretej strany. Pri odvolaní sa zmažú cookies,
  ktoré vieme podľa registra pomenovať, a stránka sa načíta znova — bežiaci
  skript sa inak odinštalovať nedá.
* **Google (GA4, Ads, GTM) sa neblokuje, riadi sa signálmi.** `consent default`
  so všetkým `denied` ide do `<head>` s `wait_for_update`, `consent update`
  chodí na vlastnú udalosť `px:consent` (pokrýva prvý súhlas, návrat aj
  odvolanie) a rovnaký stav sa tlačí do `dataLayer` ako event
  `px_consent_update`, na ktorý sa vie zavesiť GTM. Meta Pixel dostáva
  `fbq('consent', 'grant'/'revoke')`.
* **Vložené videá a mapy** sa nahradia zástupným boxom s tlačidlom; kliknutie
  povolí len tú jednu službu, nie celý marketing. YouTube sa púšťa cez
  `youtube-nocookie.com`. Bez JavaScriptu ostáva v stránke odkaz na pôvodný
  obsah, takže sa nestratí ani pre čítačku, ani pre vyhľadávač.
* **Podstránka:** shortcode `[px_cookie_policy]` vypíše z registra tabuľky per
  službu (účel, prevádzkovateľ, právny základ, cookies, odkaz na zásady).
  Stránka s predvyplneným úvodom vznikne pri zapnutí modulu **ako koncept** —
  právny text ide von až vtedy, keď si ho niekto prečíta.
* **Dva bannery sa nestretnú.** Pri aktívnom Complianze, CookieYes, Cookiebote
  a spol. sa modul sám nenačíta a v admine napíše prečo. Zapnutý modul zároveň
  vypne most `consent_mode` — signály posiela sám.
* **Známy limit:** bez certifikácie IAB TCF nestačí vlastná CMP pre AdSense ani
  Ad Manager (Google to vyžaduje od 2024). Merania GA4 a konverzií v Google Ads
  cez Consent Mode sa to netýka.
* Vzhľad patrí téme: plugin nesie len layoutové CSS (fixná pozícia, skryté
  stavy, prekrytie modálu). Šablóny sa prepisujú v téme
  (`yourtheme/px-shop-core/consent/*.php`), texty filtrami
  `px_consent_banner_title` a `px_consent_banner_text`.
* Odkaz „Nastavenia cookies" kdekoľvek v téme: stačí `data-px-consent-settings`
  na tlačidle, prípadne `window.pxConsent.open()`. Dostupnosť sa testuje
  `class_exists( 'PX_Consent' ) && PX_Consent::active()` — trieda existuje aj
  vtedy, keď modul cúvol pred externou CMP, a holý test by nakreslil tlačidlo,
  ktoré nemá čo otvoriť.
* **Udalosť `px:consent` nenesie ID súhlasu** — len verziu zásad, kategórie
  a služby. Identifikátor ostáva v cookie; cez `dataLayer` a kontajner by z neho
  bol stabilný identifikátor návštevníka, ktorý súhlas práve odmietol.
* Vracajúci sa návštevník dostane `consent update` už v `<head>`, hneď za
  `consent default` — čakanie na skript v pätičke stálo prvé `page_view`.
  Cookie sa tam číta rovnako prísne ako v `consent.js`: čo nie je boolean alebo
  nesie inú verziu zásad, nie je odpoveď, a pri viacerých cookies rovnakého
  mena platí tá najprísnejšia.
* Globálny `gtag` vzniká len vtedy, keď modul naozaj načítava knižnicu Googlu.
  Bez jediného ID ide do stránky len `consent default` — inak by cudzie pluginy
  videli `typeof gtag === 'function'` a knižnicu nenačítali.
* Vložený obsah sa neobalí dvakrát: oEmbed prejde blokovaním pri rozbalení
  a znova s celým obsahom príspevku, takže po kliknutí sa vkladalo vnútorné
  zástupné okno namiesto videa. Mapy sa poznajú na ľubovoľnej doméne Googlu
  (`google.sk/maps`) a `youtu.be` sa prepisuje na plnohodnotný
  `youtube-nocookie.com/embed/…`.
* Most `consent_mode` sa vypína sám (`PX_Consent::loaded()`), nezávisle od
  poradia v registri modulov, a nastavenia napíšu skutočný dôvod namiesto
  všeobecného „Overridden in code".
* Modál pri otvorení dá zvyšok stránky `inert` (fallback `aria-hidden`), po
  uložení voľby ide fokus na obsah stránky a po odblokovaní embedu na vzniknutý
  rámček — tlačidlo, ktoré zmizlo, by inak zhodilo fokus na začiatok dokumentu.
  Lišta je `role="region"` s názvom, nie dialóg: nič neblokuje ani nedrží fokus.
* Cookies sa pri odvolaní mažú aj na aktuálnej ceste a jej nadradených
  segmentoch, nielen na `/`.
* Register vypisuje len cookies, ktoré na webe naozaj vzniknú: `px_wishlist`
  a `px_compare` podľa zapnutých modulov, reCAPTCHA sa zapína v nastaveniach
  (v kategórii nevyhnutné, teda bez prepínača pre návštevníka) a pribudla
  `pxt_per_page` z témy.

= 1.5.1 =

**Firemné údaje** — IČO sa vyžaduje len tam, kde ho firmy naozaj majú.
Pri zapnutej povinnosti IČO pokladňa odmietla každú zahraničnú firemnú
objednávku, teda presne toho zákazníka, pre ktorého je reverse charge —
írska či nemecká firma IČO nemá. Povinnosť teraz platí len v krajinách
s registrom, ktorý poznáme (SK, CZ); mení sa filtrom
`px_company_country_has_ic`.

= 1.5.0 =

Nový modul **Google Consent Mode v2** (`consent_mode`), predvolene vypnutý:

* Posiela Googlu signály súhlasu, ktoré free verzia Complianzu nevie — tá má
  pole `consent-mode` natvrdo zakázané, hoci runtime za ním žiadnu licenciu
  nekontroluje. Complianz ostáva CMP (banner, kategórie, cookie lišta,
  právne dokumenty), modul len prekladá jeho rozhodnutia do reči Googlu.
* Bez neho web jazdí v režime „blokuj do súhlasu": Google nedostane od
  neudelených súhlasov žiadny signál, takže nie je modelovanie konverzií a
  Google Ads v EHP nepustí remarketingové publiká ani enhanced conversions.
* `consent default` so všetkým `denied` ide do `<head>`, `consent update`
  na eventoch Complianzu `cmplz_fire_categories` a `cmplz_revoke`. Mapovanie:
  `preferences` → `personalization_storage`, `statistics` →
  `analytics_storage`, `marketing` → `ad_storage`, `ad_user_data`,
  `ad_personalization`.
* Complianzu sa filtrom nad `option_cmplz_options` vypne vlastný GA snippet,
  aby sa gtag nevydával dvakrát. Do DB sa nezapisuje — sprievodca Complianzu
  vie hodnotu prepnúť späť.
* Measurement ID sa číta z Complianzu (jeden zdroj pravdy, editovateľný
  v admine), prebije ho filter `px_consent_mode_measurement_id`. Ďalšie
  filtre: `px_consent_mode_wait_for_update` (500 ms),
  `px_consent_mode_ads_data_redaction` (true),
  `px_consent_mode_url_passthrough` (false).
* Súhlas sa zámerne nečíta na strane PHP — výstup je pre všetkých rovnaký,
  takže je bezpečný voči page cache.
* **Zapnúť až vtedy**, keď na webe nič iné nevydáva gtag (napr. vlastná
  implementácia v site plugine alebo GA vkladaný Complianzom), inak sa
  načíta dvakrát.

Firemné údaje (`company_fields`):

* **Poradie polí podľa toho, ako človek rozmýšľa:** meno a priezvisko, potom
  políčko „Nakupujem na firmu" a až za ním firemný blok. Doteraz blok sedel
  nad menom, čo pýtalo IČO skôr, než zákazník povedal, kto vlastne je.
  Políčko je teraz normálne pole pokladne s prioritou, nie kus HTML nad
  formulárom, takže sa zaraďuje samo. Neukladá sa — WooCommerce si necháva
  len polia s predponou `billing_`/`shipping_`.
* **Názov firmy je súčasťou bloku** aj vtedy, keď je pole „Firma" vo
  WooCommerce nastavené na skryté. Bez neho zostalo na faktúre IČO bez mena.
  Vypne to filter `px_company_force_company_field`.
* Doplnenie z registra vyplní aj názov firmy a odškrtnutie políčka ho spolu
  s číslami vyčistí — inak by firma zostala na faktúre súkromnej osobe.
* **SuperFaktúre sa vypne jej vlastný firemný blok.** Má ho predvolene
  zapnutý, len driemal, kým v pokladni nebolo pole „Firma" — teda presne to,
  čo tento modul vracia. Inak by v pokladni boli dve políčka „nakupujem na
  firmu", dve sady polí do rôznych meta kľúčov a dva pluginy rozhodujúce
  o jednej DPH. SuperFaktúra to isté robí pre WC Nastavenia SK/CZ; údaje na
  faktúru dostáva ďalej cez `sf_client_data`.
* Z popisu políčka zmizlo „(voliteľné)" — pri zaškrtávacom políčku to znelo,
  akoby existoval nákup na firmu, ktorý je povinný.

= 1.4.0 =

Nový modul **Firemné údaje** (`company_fields`), predvolene vypnutý:

* IČO, DIČ a IČ DPH v pokladni pod voliteľným políčkom „Nakupujem na firmu",
  s pravidlami povinnosti (podľa políčka alebo podľa vyplneného názvu firmy)
  a kontrolou formátu (IČO 8 číslic, SK DIČ 10, SK IČ DPH `SK` + 10, ostatné
  EÚ prefix krajiny; SK DIČ a IČ DPH si musia odpovedať).
* **Ukladá do rovnakých kľúčov ako WPify Woo** — `_billing_ic`,
  `_billing_dic`, `_billing_dic_dph` na objednávke, bez podčiarkovníka na
  zákazníkovi. Prechod je teda výmena pluginu bez migrácie dát a staré
  objednávky sa naďalej vykresľujú. Kým je modul `ic_dic` vo WPify Woo
  zapnutý, tento sa nespustí a napíše prečo — inak by boli polia dvakrát.
* **Doplnenie z registra:** RPO (Štatistický úrad SR) pre slovenské IČO,
  ARES (MF ČR) pre české. Oboje zadarmo, bez kľúča, cez REST — žiadna
  composer závislosť, žiadny SOAP. Tlačidlo „Načítať z registra" doplní
  názov a sídlo (ARES aj DIČ). Zaniknuté subjekty sa neponúkajú a odpoveď
  RPO sa overuje na zhodu IČO — inak by preklep vyplnil cudziu firmu.
* **Overenie IČ DPH cez VIES** (REST Európskej komisie) v troch režimoch:
  vypnuté, overiť a upozorniť, alebo neplatné číslo objednávku zastaví.
  Výpadok VIES objednávku nezastaví nikdy — zastaví ju len odpoveď, že číslo
  neexistuje.
* **Prenesenie daňovej povinnosti** a **vývoz mimo EÚ**, každé samostatným
  prepínačom. Ktorá krajina rozhoduje, sa riadi nastavením WooCommerce
  „Vypočítať daň podľa" — pri tovare dodacia, pri službách fakturačná.
  Predvolene sa DPH odpúšťa **len s IČ DPH potvrdeným vo VIES**; kto to
  nechce, prepínač „Vyžadovať doklad" vypne a berie riziko na seba. Dôvod
  rozhodnutia sa ukladá na objednávku (`_px_vat_exempt_reason`), vypisuje sa
  v administrácii a ide do WC logu — VIES odpovedá len o dnešku, o rok sa už
  nedozviete nič.
* Odpovede sa kešujú (firma týždeň, platné IČ DPH deň, neplatné hodinu) a
  REST endpointy `pixeler/v1/company/lookup` a `.../vat` majú limit na IP —
  obchod nesmie byť najlacnejšia cesta, ako register vyťažiť.
* Údaje idú do administrácie objednávky, do profilu zákazníka, do
  formátovanej adresy a e-mailov. SuperFaktúra ich číta len keď beží WPify
  Woo, takže sa jej podávajú cez filter `sf_client_data`.
* Vzhľad patrí téme: skript len prepína `hidden` a nasadzuje `px-company-*`
  triedy. Pre šablóny `px_company_order_details()` a `px_company_vat_reason()`.

Nový modul **Súvisiace kategórie** (`related_cats`):

* Doplnky sa nastavujú **raz na kategórii**, nie na každom produkte:
  *Bicykle* → *Svetlá*, *Košíky na fľaše*, *Pedále*. Každý produkt
  v kategórii potom ponúka produkty z tých kategórií.
* Väzba je **jednosmerná** zámerne — „bicykle → svetlá" nerobí „svetlá →
  bicykle". Svetlo k bicyklu je doplnok, bicykel k svetlu nie.
* **Dedí sa po strome:** kategória bez vlastného nastavenia sa spýta
  rodiča, takže celá vetva *Bicykle* sa nastaví na jednom mieste.
  Obrazovka kategórie napíše, odkiaľ zoznam pochádza, a prehľad
  v Produkty → Kategórie ukáže stĺpec **Súvisiace**.
* Produkty sa berú **striedavo z každej kategórie**, nech riadok nezaplnia
  samé pedále. Poradie v rámci kategórie je voliteľné (najpredávanejšie,
  najnovšie, poradie v kategórii) — nie náhodné, to by znemožnilo cache.
* **Výkon:** zoznam ID sa kešuje na *množinu kategórií*, nie na produkt —
  všetky bicykle zdieľajú jeden záznam. Trafená cache nestojí ani jeden
  databázový dotaz, minutá jeden indexovaný dotaz na kategóriu. Neplatní
  ju uloženie kategórie (verzia kľúča), nie uloženie produktu — ceny
  a sklady sa menia oveľa častejšie než zoznam doplnkov.
* Vypredané a z katalógu vylúčené produkty sa neponúkajú (rešpektuje
  „skryť vypredané").
* Vykreslenie patrí téme: `PX_Related_Cats::get_product_ids()`
  (`px_related_category_ids()`) a `::get_title()`. Filtre
  `px_related_cats_term_ids`, `px_related_cats_product_ids`,
  `px_related_cats_query_args` a `px_related_cats_title` majú posledné
  slovo.
* Voliteľne aj **v košíku**: doplnky sa pridajú ku krížovému predaju
  (`woocommerce_cart_crosssell_ids`) za tie, ktoré má produkt nastavené
  sám. Klasický košík; blokový si kreslí vlastné. Predvolene vypnuté.

Nový modul **Platnosť akcie** (`sale_dates`):

* WooCommerce koniec akcie pozná (`_sale_price_dates_to`), ale nikde ho
  neukáže. Modul dátum vyhľadá a podá téme ako timestamp
  (`PX_Sale_Dates::get_end()`, `px_sale_end()`).
* Variabilný produkt dátum na sebe zvyčajne nemá — akcia končí poslednou
  zľavnenou variáciou. Jedna zľavnená variácia bez dátumu drží akciu
  otvorenú (nesľubujeme koniec, ktorý nenastane).
* Obchody, ktoré zľavňujú pluginom cez cenové filtre, nemajú na produkte
  žiadny dátum — pre ne je v nastaveniach **koniec kampane pre celý
  obchod**. Filter `px_sale_end_date` má posledné slovo: dátum dodá aj
  potlačí (návratom 0).
* Nastavenia: zobrazenie na kartách a na detaile zvlášť, prah pre živý
  odpočet v hodinách (0 = len dátum) a „skryť, ak je koniec ďalej než N
  dní" — akcia bežiaca mesiace nie je novina.
* Timestamp cestuje aj vo `woocommerce_available_variation`
  (`px_sale_end`), takže téma vie pás prekresliť pri výbere variácie.
* Znenie a značky patria téme (`get_end()`); `get_html()` je záloha pre
  témy, ktoré si nekreslia vlastné.

Omnibus:

* **Oprava: pri variabilnom produkte s rovnakou cenou vo všetkých variáciách
  zmizla po výbere variácie cena.** WooCommerce v takom prípade posiela
  `price_html` prázdny — je to pokyn „nechaj cenu, čo je na stránke" — a
  Omnibus doň pripájal svoj riadok. Z pokynu sa tak stal cenový blok bez
  ceny, ktorý téma poslušne vykreslila. Do prázdneho `price_html` sa už
  nepripája.
* Riadok chodí aj samostatne ako `px_omnibus_html` vo dátach variácie a nový
  filter `px_omnibus_variation_price_html` vypne pripájanie do ceny — téma,
  ktorá má preň vlastné miesto, ho inak mala pod cenou dvakrát.

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
