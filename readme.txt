=== PX Shop Core ===
Contributors: pixeler
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.0
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
