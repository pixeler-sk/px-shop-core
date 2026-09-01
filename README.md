# PX Shop Core

Zdieľané WooCommerce funkcie pre Pixeler eshopy. Prezentácia žije v téme —
tento plugin dodáva len funkcionalitu (neutrálny `px-*` markup, dáta, REST).
Každá funkcia je samostatný modul, dá sa vypnúť a téma sa jej pýta cez
`class_exists()`.

- Vyžaduje WooCommerce (okrem modulov `content` a `consent_mode`);
  WordPress ≥ 6.0, PHP ≥ 7.4.
- **Inštalácia a aktualizácie:** [RELEASING.md](RELEASING.md) — releases cez
  tagy, weby sa aktualizujú cez Plugin Update Checker.
- Changelog po verziách: [readme.txt](readme.txt).
- Licencia: GPL v2 or later.

## Obsah

- [Moduly a nastavenia](#moduly-a-nastavenia)
- [Prehľad modulov](#prehľad-modulov)
- [Omnibus](#omnibus-omnibus) · [Platnosť akcie](#platnosť-akcie-sale_dates) ·
  [GPSR](#gpsr-gpsr) · [Firemné údaje](#firemné-údaje-company_fields) ·
  [Live search](#live-search-search) · [Brand tab](#brand-tab-brand_tab) ·
  [Wishlist](#wishlist-wishlist) · [Compare](#compare-compare) ·
  [Waitlist](#waitlist-waitlist) · [Veľkostné tabuľky](#veľkostné-tabuľky-size_guide) ·
  [Súvisiace kategórie](#súvisiace-kategórie-related_cats) ·
  [Shipping bar](#shipping-bar-shipping_bar) · [Katalógový režim](#katalógový-režim-catalog) ·
  [Obrázky atribútov](#obrázky-atribútov-attribute_image) ·
  [Content bloky](#content-bloky-content) · [Súhlas s cookies](#súhlas-s-cookies-consent) ·
  [Google Consent Mode v2](#google-consent-mode-v2-consent_mode)
- [Prístupnosť (WCAG)](#prístupnosť-wcag)
- [WP-CLI](#wp-cli)
- [Page cache](#page-cache)
- [Konvencie pre tému](#konvencie-pre-tému)

## Moduly a nastavenia

Každý modul sa dá vypnúť vo **WooCommerce → Nastavenia → PX Shop**. Vypnutý
modul sa vôbec nenačíta — neregistruje hooky, REST routy, admin obrazovky ani
WP-CLI príkazy, takže `class_exists( 'PX_Wishlist' )` ostáva pre tému
spoľahlivým testom dostupnosti. Uložené dáta zostávajú aj po vypnutí
(obľúbené, prihlásenia na naskladnenie, GPSR polia) a po zapnutí sa vrátia.

Stav modulov je jedna option `px_shop_core_modules`; kým ju nikto neuloží,
platia predvolené hodnoty z registra. Register je
v [includes/modules.php](includes/modules.php):

| Filter | Na čo |
| --- | --- |
| `px_shop_core_modules` | pridá vlastný modul site pluginu — dostane prepínač, sekciu nastavení a načítanie zadarmo |
| `px_shop_core_module_on` | pripne stav modulu v kóde (weby vedené cez git); nastavenia to napíšu ako „nastavené v kóde" |

Moduly s vlastnou sekciou nastavení majú v PX Shop vlastnú záložku.

## Prehľad modulov

| Modul | Kľúč | Predvolene | Čo robí |
| --- | --- | --- | --- |
| Omnibus | `omnibus` | zap. | história cien, najnižšia cena za 30 dní pri zľave |
| Platnosť akcie | `sale_dates` | zap. | dokedy platí zľavnená cena — dátum alebo odpočet |
| GPSR | `gpsr` | zap. | bezpečnosť výrobku: výrobca, zodpovedná osoba v EÚ, pôvod, upozornenia, dokumentácia |
| Firemné údaje | `company_fields` | **vyp.** | IČO/DIČ/IČ DPH v pokladni, RPO/ARES, VIES, reverse charge, vývoz mimo EÚ |
| Live search | `search` | zap. | REST `pixeler/v1/search` pre šepkávač v hlavičke |
| Brand tab | `brand_tab` | zap. | tab „O značke" z popisu termu `product_brand` |
| Wishlist | `wishlist` | zap. | obľúbené (user meta / cookie), REST, shortcode |
| Compare | `compare` | zap. | porovnanie až 4 produktov (cookie), REST, shortcode |
| Waitlist | `waitlist` | zap. | stráženie dostupnosti s double opt-in a 4 e-mailmi |
| Veľkostné tabuľky | `size_guide` | zap. | tabuľky veľkostí na produkte alebo zdedené z kategórie |
| Súvisiace kategórie | `related_cats` | zap. | doplnky nastavené raz na kategórii, dedia sa po strome |
| Shipping bar | `shipping_bar` | zap. | dáta pre lištu „do dopravy zadarmo chýba…" |
| Katalógový režim | `catalog` | zap. (režim sám vyp.) | výklad bez cien a košíka |
| Obrázky atribútov | `attribute_image` | zap. | obrázok na terme atribútu `pa_*` pre swatche a filtre |
| Content bloky | `content` | zap. | skrytý CPT pre bannery, USP pásy a iné bloky kreslené témou |
| Súhlas s cookies | `consent` | **vyp.** | vlastná CMP: lišta, blokovanie, stránka zásad, Consent Mode v2 |
| Google Consent Mode v2 | `consent_mode` | **vyp.** | signály pre Google popri free Complianze |

## Omnibus (`omnibus`)

Smernica Omnibus: pri zľavnenom produkte sa ukazuje najnižšia cena za
posledných 30 dní. Plugin si históriu cien zapisuje sám pri uložení produktu,
takže web, ktorý zapne modul dnes, má o mesiac úplné dáta.

Naplánovaná zľava ale prepne cenu bez uloženia, ktoré by novú hodnotu nieslo
(WooCommerce zapisuje `_price` cez `update_post_meta()` až po tom, čo `save()`
odpálil svoje hooky). Zápis „pri zobrazení detailu" to pod page cache
nezachráni — PHP sa spustí len pri cache miss. Preto beží **denný cron
`px_omnibus_scan`** (03:20 miestneho času, hneď po polnočnej práci
WooCommerce), ktorý prejde všetko v zľave a dopíše, čo chýba. Zápis pri
zobrazení ostáva ako záchrana.

| Čo | Ako |
| --- | --- |
| Najnižšia cena | `PX_Omnibus::get_lowest_price( $product )` (float), `::get_html( $product )` |
| História | `PX_Omnibus::get_history( $product_id )` |
| Ručný beh | `PX_Omnibus::scan()` — vráti počet produktov s novým záznamom |
| Čo scan berie | `onsale = 1` z `wc_product_meta_lookup` + produkty s `_sale_price_dates_*` v okne ±3 dni; po dávkach po 200, dva dotazy na dávku, bez načítania produktových objektov a bez stropu (na libike ~4 300 produktov za desatiny sekundy) |
| Variácie | v dátach variácie cestuje `px_omnibus_html`; do `price_html` sa pripája len ak nie je prázdny |
| Filtre | `px_omnibus_display` (vypne výpis, história vzniká ďalej — web, ktorý cenu ukazuje iným pluginom, neskôr prejde bez diery v dátach), `px_omnibus_lowest_price`, `px_omnibus_variation_price_html`, `px_omnibus_scan_limit` (0 = bez stropu), `px_omnibus_scan_ids` |

## Platnosť akcie (`sale_dates`)

WooCommerce koniec akcie pozná (`_sale_price_dates_to`), ale nikde ho neukáže.
Modul dátum vyhľadá (pri variabilnom produkte podľa poslednej zľavnenej
variácie) a podá téme ako timestamp.

- Nastavenia: zobrazenie na kartách a na detaile zvlášť, prah pre živý odpočet
  v hodinách (0 = len dátum), „skryť, ak je koniec ďalej než N dní", **koniec
  kampane pre celý obchod** (pre zľavy robené cenovými filtrami bez dátumu).
- `PX_Sale_Dates::get_end( $product )` / `px_sale_end()` — timestamp alebo 0;
  `::get_html()` záložné vykreslenie; `::show( 'single'|'loop' )`.
- Vo `woocommerce_available_variation` cestuje `px_sale_end`.
- Filter `px_sale_end_date` má posledné slovo (dátum dodá aj potlačí
  návratom 0).

## GPSR (`gpsr`)

Nariadenie o všeobecnej bezpečnosti výrobkov (GPSR, EÚ 2023/988). Produkt
má v administrácii záložku **Bezpečnosť** a na fronte produktový tab
s rovnakým obsahom.

Každý údaj má práve jedno miesto:

| Kde | Polia | Prečo |
| --- | --- | --- |
| Značka (Produkty → Značky, `product_brand`) | výrobca, zodpovedná osoba v EÚ | opakujú sa na každom produkte značky; na produkte sa ukazujú len na čítanie s odkazom na značku, produkt s iným výrobcom dostane inú značku |
| Produkt | krajina pôvodu, bezpečnostné upozornenia, URL bezpečnostnej dokumentácie | líšia sa kus od kusu |

- `PX_GPSR::get_data( $product )` vráti zlúčené dáta pre vlastnú šablónu.
- Filtre: `px_gpsr_fields` (sada polí; web, ktorý má niektoré inde, ho vypustí),
  `px_gpsr_entity_fields` (ktoré polia patria značke).
- Meta kľúče `_px_gpsr_*` na produkte, `px_gpsr_*` na terme značky.

## Firemné údaje (`company_fields`)

Predvolene vypnutý — zapnúť až keď je preč plugin, ktorý polia riešil
doteraz (WPify Woo `ic_dic`: kým beží, modul sa nespustí a napíše prečo).

- **Polia v pokladni:** políčko „Nakupujem na firmu" za menom, pod ním
  názov firmy, IČO, DIČ, IČ DPH. Pravidlá povinnosti (podľa políčka alebo
  vyplneného názvu firmy; IČO povinné len v krajinách s registrom — SK, CZ,
  filter `px_company_country_has_ic`), kontrola formátu (IČO 8 číslic, SK DIČ
  10, SK IČ DPH `SK`+10, EÚ prefix krajiny; SK DIČ a IČ DPH si musia
  odpovedať).
- **Rovnaké meta kľúče ako WPify Woo:** `_billing_ic`, `_billing_dic`,
  `_billing_dic_dph` na objednávke, bez podčiarkovníka na zákazníkovi —
  výmena pluginu bez migrácie.
- **Doplnenie z registra:** RPO (ŠÚ SR) pre SK, ARES (MF ČR) pre CZ,
  zadarmo bez kľúča. Tlačidlo „Načítať z registra" doplní názov a sídlo.
  Zaniknuté subjekty sa neponúkajú.
- **VIES:** vypnuté / overiť a upozorniť / neplatné číslo zastaví objednávku.
  Výpadok VIES objednávku nikdy nezastaví.
- **Prenesenie daňovej povinnosti** a **vývoz mimo EÚ**, každé samostatne.
  Rozhodujúca krajina podľa WooCommerce „Vypočítať daň podľa". Predvolene len
  s IČ DPH potvrdeným vo VIES („Vyžadovať doklad"). Dôvod sa ukladá
  (`_px_vat_exempt_reason`), ukazuje v admine a loguje.
- Cache (firma týždeň, platné IČ DPH deň, neplatné hodinu), REST endpointy
  `pixeler/v1/company/lookup` a `.../vat` s limitom na IP
  (`px_company_rate_limit`, 30).
- Údaje idú do admin objednávky, profilu zákazníka, formátovanej adresy,
  e-mailov a cez `sf_client_data` do SuperFaktúry (jej vlastný firemný blok
  sa vypne).
- Šablóny: `px_company_order_details( $order )`,
  `px_company_vat_reason( $order )`. JS len prepína `hidden` a nasadzuje
  `px-company-*` triedy.
- Ďalšie filtre: `px_company_force_company_field`, `px_company_use_dic_dph`,
  `px_company_vat_destination`, `px_company_vat_decision`,
  `px_company_register_countries`, `px_company_details`.

## Live search (`search`)

REST `GET pixeler/v1/search?term=…` vráti produkty a zodpovedajúce kategórie
pre šepkávač v hlavičke. Rešpektuje „skryť vypredané", vynecháva kategórie
bez viditeľných produktov a položka nesie `in_stock`.

**Známy rozdiel:** počet za „Zobraziť všetkých N výsledkov" sedí s archívom,
ale nie nutne so stránkou výsledkov vyhľadávania. Šepkávač vypredané skrýva
(keď je voľba zapnutá), kým WooCommerce na stránke výsledkov aplikuje len
`exclude-from-search` — `hide_out_of_stock_items` rieši v `get_tax_query()`,
ktorý beží na archívoch, nie na vyhľadávaní. Weby, ktoré chcú rovnaké čísla,
si vypredané skrývajú vlastným `pre_get_posts` (tak to má libike).

Filtre: `px_shop_core_search_limit` (8), `px_shop_core_search_cat_limit` (4),
`px_shop_core_search_query_args`, `px_shop_core_search_code_ids`,
`px_shop_core_search_code_limit`, `px_shop_core_search_response`.

### Vyhľadávanie podľa SKU a EAN

Hľadá sa aj podľa **SKU** (`_sku`) a **EAN/GTIN** (`_global_unique_id`) —
v šepkávači aj na štandardnej stránke výsledkov (`?s=…&post_type=product`,
cez filter `posts_search`). WordPress prehľadáva len názov, perex a obsah;
nalepený kód dielu ani pípnutý čiarový kód dovtedy nevrátili nič. Zhoda
variácie sa mapuje na rodiča — variácia nemá vlastnú stránku a na krabici býva
jej SKU.

| Pravidlo | Prečo |
| --- | --- |
| SKU presne + podľa predpony od 4 znakov | predpona SKU nesie význam (SKU variácie predlžuje rodičovské), ale „RAM" nesmie ťahať pol katalógu |
| EAN/GTIN len presne | pevná dĺžka, čiastočný kód nič neznamená — a stĺpec nemá index, takže sa k nemu bežné slovo vôbec nedostane |
| presná zhoda potláča prefixové | keď kód nesie konkrétny produkt, rodina s rovnakým začiatkom SKU je šum; presné SKU tak vráti jeden výsledok — a pri jednom výsledku presmeruje **WooCommerce** (`wc_template_redirect()`, filter `woocommerce_redirect_single_search_result`) rovno na produkt, ale len keď je v URL `post_type=product`, teda z formulára v hlavičke |
| prefixové zhody sa radia podľa dĺžky SKU | keď ich je viac než strop, rez nesmie padnúť podľa ID produktu — päťdesiat najstarších z päťsto neodpovedá na nič. Prežije to, čo je najbližšie napísanému |
| variácia → rodič, `GROUP BY`, strop | žiadne dotazy v cykle, jeden `$wpdb->prepare` dotaz na otázku |

Zdroj dát je `wc_product_meta_lookup` (riadok na produkt, `sku` indexované, ten
istý zdroj, na akom beží vyhľadávanie v administrácii WooCommerce). SKU a EAN sa
pýtajú **dvoma dotazmi**, nie jedným `OR`: index má len `sku`, a spoločná
podmienka by oň optimalizátor pripravil — každá číselná fráza (aj bežné číselné
SKU) by potom znamenala full scan lookup tabuľky. Najprv teda beží indexovaná
otázka na SKU a `global_unique_id` sa pýta, až keď nič nevrátila.

Lookup je cache, ktorú WooCommerce prepisuje pri uložení produktu — importér
zapisujúci `_sku` priamo cez `update_post_meta()` ju vie nechať pozadu. Keď kód,
o ktorom vieš, že existuje, vyhľadávanie nenájde, správna oprava je
**WooCommerce → Stav → Nástroje → *Obnoviť vyhľadávacie tabuľky produktov***.
Dohľadanie priamo v `postmeta` v kóde ostáva, ale beží **len pod WP-CLI**
(`PX_Search::product_ids_matching_code()` z `wp eval`) — je to sken každého
`_sku` a `_global_unique_id` riadku bez indexu, čo verejné vyhľadávacie pole
spúšťať nesmie: crawler s vymyslenými kódmi by ho vyvolal na každý request.
Slúži na overenie, či je lookup pozadu.

Stĺpec `global_unique_id` pribudol vo WooCommerce 9.1; na starších inštaláciách
sa EAN cez front end nenájde (prítomnosť stĺpca sa zisťuje raz za týždeň,
transient s číslom verzie WooCommerce v kľúči).

Na stránke výsledkov sa nájdené ID pripájajú do klauzuly `posts_search`
(`OR ID IN (…)`), takže `post_type`, `post_status`, ochrana heslom aj
`tax_query` na `product_visibility` z hlavného dopytu platia ďalej —
viditeľnosť sa nefiltruje druhýkrát. Podmienka na `post_password` sa pred
`OR` odtrhne a prilepí späť za neho; ak po tom v reťazci ostane čo i len
zmienka o `post_password`, alebo fragment nemá presne tvar `AND (…)`, ktorý
stavia jadro, filter dotaz nechá nezmenený. Žiadny best effort — polovične
pochopený WHERE je presne to, čím sa vo výsledkoch začnú objavovať heslom
chránené a nepublikované príspevky. Vedľajšie dopyty (widgety, súvisiace
produkty) a administrácia — tá má vlastné vyhľadávanie podľa SKU od
WooCommerce — ostávajú nedotknuté. Hlavné vyhľadávanie bez `post_type`
WordPress rieši ako `any`, takže kód nájde produkt aj z bežného
vyhľadávacieho poľa.

Helper `PX_Search::product_ids_matching_code( $term, $limit )` je verejný.
Filter `px_shop_core_search_code_ids( $ids, $term, $limit )` je pre obchod,
ktorý drží čísla dielov inde (vlastný meta kľúč, dodávateľská tabuľka) —
zapojí sa sem namiesto toho, aby si vyhľadávanie písal odznova.

Odpoveď je 60 sekúnd v transiente a posiela `Cache-Control: public,
max-age=60`. Endpoint je verejný a bez rate limitu, pri šepkávači ide request
na každé písmeno — bez cache by opakovaná fráza púšťala `WP_Query` aj
`get_terms()` nanovo. Cachuje sa stav pred `px_shop_core_search_response`, ten
filter beží aj nad cachovanými dátami (nech je lacný a bez údajov viazaných na
konkrétneho návštevníka).

Kľúč nesie normalizovanú frázu (malé písmená, zúžené medzery — „Prilba"
a „prilba" je tá istá otázka), jazyk, a všetko, od čoho závisí `price_html`:
menu, nastavenie „zobrazovať ceny s daňou / bez dane" a oslobodenie od DPH
(`WC()->customer->get_is_vat_exempt()`, ktoré prepína aj modul firemných údajov
tohto pluginu). Bez toho by prvý návštevník s iným daňovým kontextom otrávil
ceny všetkým na 60 sekúnd. **Prihlásenému zákazníkovi sa necachuje vôbec** a
zapisuje sa **len s perzistentnou object cache** (`wp_using_ext_object_cache()`)
— inak by každá neznáma fráza z verejného endpointu znamenala dva riadky v
`wp_options` a crawler by ich narobil státisíce. Fráza je obmedzená na 64
znakov. `viewAll` sa do cache nedáva: nesie pôvodné písanie návštevníka.

Dôsledok: **na webe bez object cache serverová cache šepkávača nie je** a
zostáva len hlavička `Cache-Control`, ktorá opakované písmená zachytí
v prehliadači a na CDN. Kto chce cache aj na serveri, zapne Redis.

`px_shop_core_search_query_args( $args, $term )` dostane WP_Query argumenty
skôr, než dotaz pobeží. Slúži na to, aby si web preložil časť frázy na
atribútový filter — e-shop s rozmermi chce z „matrac 90x200" spraviť
`tax_query` nad `pa_rozmer` a fulltextu nechať len „matrac", čo obyčajné LIKE
nad `post_title` nikdy netrafí. Názvy atribútov patria site pluginu, plugin
ich neháda. Ak filter skráti `$args['s']`, nech ho nechá orezaný — vyhľadanie
kategórie beží nad tou istou hodnotou (a pri prázdnej sa preskočí), takže
spotrebované tokeny neprepadnú do zhody na názov kategórie.

## Brand tab (`brand_tab`)

Produktový tab „O značke" z popisu termu `product_brand` + logo značky.
`PX_Brand_Tab::get_brands_with_description( $product )` pre vlastné
vykreslenie.

## Wishlist (`wishlist`)

Obľúbené produkty: prihlásený zákazník v user meta, hosť v cookie
`px_wishlist`; pri prihlásení sa cookie zlúči do účtu.

| Čo | Ako |
| --- | --- |
| REST | `GET px-shop-core/v1/wishlist`, `POST px-shop-core/v1/wishlist/toggle` |
| Helpery | `px_wishlist_ids()`, `px_wishlist_has( $id )`, `px_wishlist_count()`, `px_wishlist_url()` |
| Stránka | shortcode `[px_wishlist]`, stránka v nastaveniach (`px_wishlist_page_id`) |
| Filtre | `px_wishlist_url`, `px_products_grid_classes` |
| CLI | `wp px wishlist migrate [--dry-run]` (prevod z Woodmartu) |

## Compare (`compare`)

Porovnanie až štyroch produktov, cookie `px_compare`.

| Čo | Ako |
| --- | --- |
| REST | `GET px-shop-core/v1/compare`, `POST px-shop-core/v1/compare/toggle` |
| Helpery | `px_compare_ids()`, `px_compare_has( $id )`, `px_compare_count()`, `px_compare_url()` |
| Stránka | shortcode `[px_compare]` (tabuľka atribútov), stránka v nastaveniach (`px_compare_page_id`) |
| Filtre | `px_compare_attribute_rows`, `px_compare_url` |

## Waitlist (`waitlist`)

Stráženie dostupnosti vypredaného produktu (aj variácie).

- **Double opt-in** — adresa sa počíta až po kliknutí v e-maile; odhlásenie
  jedným odkazom v každej správe maže adresu zo všetkých produktov.
- Štyri e-maily ako bežné WooCommerce triedy (WooCommerce → Nastavenia →
  E-maily, šablóny `woocommerce/emails/px-waitlist-*.php`): potvrdenie
  adresy, potvrdené prihlásenie, naskladnenie, notifikácia pre admina.
- Formulár `PX_Waitlist::render_form( $product )` / `::get_form_html()`,
  REST `POST px-shop-core/v1/waitlist`.
- Metabox na produkte so zoznamom prihlásených, `PX_Waitlist::count()`.
- Akcie: `px_waitlist_subscribed`, `px_waitlist_confirmed`,
  `px_waitlist_unsubscribed`, `px_waitlist_back_in_stock`. Filtre:
  `px_waitlist_require_confirmation`, `px_waitlist_show_form`.
- CLI: `wp px waitlist migrate [--dry-run]` z Woodmartu.

## Veľkostné tabuľky (`size_guide`)

CPT `px_size_guide` (Produkty → Veľkostné tabuľky) s tabuľkou (riadok na
riadok, bunky cez `|` alebo tabulátor) a voliteľným textom. Produkt si
tabuľku vyberie sám, inak zdedí z kategórie; „nezobrazovať" potlačí aj
kategóriu.

- `PX_Size_Guide::render()` — odkaz + modál (`<dialog>`), `::get_content_html()`
  samotný obsah, `::has_guide()`. Témy so
  `woocommerce_single_product_summary` dostanú modál samy.
- Filter `px_size_guide_button_text`.
- CLI: `wp px size-guide migrate [--dry-run]` z Woodmartu; dočasný most
  číta `woodmart_sguide_select`, kým dáta nie sú prevedené.

## Súvisiace kategórie (`related_cats`)

Doplnky raz na kategórii: *Bicykle → Svetlá, Košíky na fľaše*. Väzba je
jednosmerná a dedí sa po strome (podkategória bez nastavenia sa pýta rodiča;
stĺpec **Súvisiace** v Produkty → Kategórie). Produkty sa berú striedavo
z každej kategórie, poradie voliteľné (najpredávanejšie / najnovšie / poradie
v kategórii), vypredané a z katalógu vylúčené sa neponúkajú.

- Cache na *množinu kategórií*, neplatní ju uloženie kategórie.
- `PX_Related_Cats::get_product_ids( $product_id, $limit )` /
  `px_related_category_ids()`, `::get_title()`.
- Voliteľne aj v košíku (cross-sell, klasický košík).
- Filtre: `px_related_cats_term_ids`, `px_related_cats_product_ids`,
  `px_related_cats_query_args`, `px_related_cats_title`.

## Shipping bar (`shipping_bar`)

Dáta pre lištu „do dopravy zadarmo chýba X €": `px_free_shipping_state()`
vráti `null` (doprava zadarmo sa neponúka) alebo
`[ threshold, remaining, progress 0–100 ]`. Prah sa berie z metódy Free
shipping v aktívnej zóne (počíta sa ako WooCommerce: zobrazený medzisúčet
mínus zľavy); filter `px_free_shipping_threshold`.

## Katalógový režim (`catalog`)

„Výklad" bez nákupu: režim a skrytie cien sa zapína v nastaveniach modulu,
s voliteľným náhradným tlačidlom (text + odkaz, napr. „Dopyt"). Téma
používa `px_catalog_mode()`, `px_catalog_hide_price()`,
`px_catalog_button( $product, $classes )`; filter `px_catalog_button_class`.

## Obrázky atribútov (`attribute_image`)

Pole s obrázkom na každom terme atribútu `pa_*` (pre swatche a filtre), so
stĺpcom v zozname termov. `PX_Attribute_Image::image_id( $term_id )`,
`::image( $term_id, $size, $attr )`; helpery `px_get_term_image_id()`,
`px_get_term_image()` sú chránené pred redeklaráciou.

## Content bloky (`content`)

Neverejný CPT `px_content` pre znovupoužiteľné bloky (bannery, USP pásy)
s layoutmi (`px_content_layouts`, predvolene `media-right`), zarovnaním a
kategóriami. Nezávisí od WooCommerce.

- Helpery: `px_get_content_items( $category, $limit )`,
  `px_get_banner( $post )`, `px_banner( $item, $args )`,
  `px_banners( $args )`, `px_content_template( $name, $args )`.
- Shortcode `[px_banner]`. Šablóny v `templates/content/`, prepis
  v téme cez `px_content_locate_template`.
- Filtre: `px_content_banner_data`, `px_content_banner_html`,
  `px_content_banner_classes`, `px_content_text_html`,
  `px_content_default_layout`, `px_content_style_handle`.

## Súhlas s cookies (`consent`)

Vlastná CMP namiesto Complianzu. **Predvolene vypnutá** — web, ktorý si necháva
externú CMP (CookieYes, Complianz, Cookiebot), ju nechá vypnutú a z modulu sa
nenačíta nič. Zapnutý modul sa pri aktívnej cudzej CMP sám nespustí a napíše to
v admine; zároveň vypne most `consent_mode`, lebo signály posiela sám.

Nastavenia: **WooCommerce → Nastavenia → PX Shop → Súhlas s cookies** (služby,
ich ID, verzia zásad, stránka so zásadami).

Čo modul robí:

- **Register služieb je jediný zdroj pravdy** — jedna deklarácia v PHP živí
  lištu, modál, stránku so zásadami aj blokovanie. Vstavaný katalóg: GA4,
  Google Ads, GTM, Meta Pixel, Microsoft Clarity, Smartsupp, Heureka,
  YouTube, Google Maps, reCAPTCHA + cookies WordPressu, WooCommerce a PX Shop.
- **Lišta v dvoch vrstvách:** *Prijať všetko / Odmietnuť všetko / Nastavenia*
  rovnakej váhy, žiadne predznačené políčka, žiadna cookie wall; v modále
  prepínače per kategória aj per služba.
- **Súhlas v cookie `px_consent`** (verzia zásad, čas, ID, kategórie, služby),
  platnosť 182 dní. Zmena **Verzie zásad** = lišta sa spýta znova. PHP cookie
  nečíta — výstup je rovnaký pre všetkých, page cache nemá čo pokaziť.
- **Blokovanie** skriptov cez `type="text/plain"`; pri odvolaní sa zmažú známe
  cookies a stránka sa načíta znova.
- **Google (GA4, Ads, GTM)** sa neblokuje, riadi sa signálmi Consent Mode v2:
  `consent default` denied v `<head>`, `consent update` na `px:consent`,
  `dataLayer` event `px_consent_update`. Meta Pixel dostáva `fbq('consent')`.
- **Vložené videá a mapy** dostanú zástupný box s tlačidlom; YouTube cez
  `youtube-nocookie.com`; bez JS ostáva odkaz na pôvodný obsah.
- **Podstránka** so shortcodom `[px_cookie_policy]` vzniká pri zapnutí ako
  koncept.

Kontrakt pre tému a site plugin:

| Čo | Ako |
| --- | --- |
| Je funkcia dostupná? | `class_exists( 'PX_Consent' ) && PX_Consent::active()` |
| Odkaz „Nastavenia cookies" | `data-px-consent-settings` na tlačidle, alebo `window.pxConsent.open()` |
| Reakcia na voľbu | `document.addEventListener( 'px:consent', e => e.detail.categories )` |
| Vlastný skript tretej strany | `<script type="text/plain" data-px-consent="marketing" data-px-service="id">` |
| Vlastná služba v registri | filter `px_consent_services` (kategórie `px_consent_categories`) |
| Prepis šablóny | `themes/<tema>/px-shop-core/consent/banner.php` (aj `modal.php`, `policy.php`, `embed.php`) |
| Texty lišty | `px_consent_banner_title`, `px_consent_banner_text` |
| Platnosť súhlasu | `px_consent_lifetime_days` (predvolene 182) |
| Ďalšie filtre | `px_consent_show`, `px_consent_external_cmp`, `px_consent_page_url`, `px_consent_page_content`, `px_consent_reload_on_revoke`, `px_consent_service_snippet`, `px_consent_embeds`, `px_consent_wait_for_update`, `px_consent_ads_data_redaction`, `px_consent_url_passthrough` |

**`::active()`, nie holý `class_exists`.** Trieda sa načíta aj vtedy, keď modul
cúvol pred externou CMP alebo ho pre danú požiadavku vypol filter
`px_consent_show` — vtedy nie je lišta ani modál, takže tlačidlo v pätičke by
nemalo čo otvoriť. `PX_Consent::loaded()` je príbuzný test „modul je tu pánom
súhlasu" bez ohľadu na požiadavku (používa ho most `consent_mode`).

Udalosť `px:consent` nesie `{ version, categories, services }` — **žiadne ID
súhlasu**. To ostáva len v cookie, nikdy nejde do `dataLayer` ani do kontajnera.

Vzhľad patrí téme — plugin nesie len layoutové CSS (`assets/consent.css`),
komponent `.pc-scope` je v `px-shop-theme`.

Limit: bez certifikácie IAB TCF nestačí vlastná CMP pre AdSense ani Ad Manager.
Meranie GA4 a konverzií v Google Ads cez Consent Mode v2 to neobmedzuje.

## Google Consent Mode v2 (`consent_mode`)

Most pre weby, kde CMP ostáva **Complianz** (free verzia má Consent Mode
zakázaný). Predvolene vypnutý, vyžaduje Complianz; modul `consent` ho vypína
sám.

- `consent default` so všetkým `denied` v `<head>`, `consent update` na
  eventoch Complianzu (`cmplz_fire_categories`, `cmplz_revoke`). Mapovanie:
  preferences → `personalization_storage`, statistics → `analytics_storage`,
  marketing → `ad_storage`, `ad_user_data`, `ad_personalization`.
- Measurement ID z Complianzu (filter `px_consent_mode_measurement_id`);
  Complianzu sa vypne vlastný GA snippet bez zápisu do DB.
- Filtre: `px_consent_mode_wait_for_update` (500 ms),
  `px_consent_mode_ads_data_redaction` (true),
  `px_consent_mode_url_passthrough` (false).
- Zapnúť až vtedy, keď nič iné na webe nevydáva gtag.

## Prístupnosť (WCAG)

Plugin nesie len markup, vizuál dodáva téma — ale markup je písaný tak, aby
téma mala z čoho stavať na úroveň WCAG 2.1 AA:

- **Lišta súhlasu** je `role="region"` s názvom (nič neblokuje ani nedrží
  fokus); **modál nastavení** je `role="dialog"` + `aria-modal`,
  `aria-labelledby`/`aria-describedby`, focus trap, ovládanie klávesnicou,
  Esc zatvára, zvyšok stránky dostane `inert` (fallback `aria-hidden`).
  Po uložení voľby ide fokus na obsah stránky, po odblokovaní embedu na
  vzniknutý rámček. Prepínače nesú `aria-expanded`/`aria-controls`, stavy
  `:focus-visible`.
- Tlačidlá lišty majú rovnakú váhu, nič nie je predznačené, žiadna cookie
  wall, odmietnutie je jeden klik (aj požiadavka ÚOOÚ/GDPR, nie len WCAG).
- **Zástupné boxy embedov** nechávajú bez JS odkaz na pôvodný obsah — nestratí
  sa pre čítačku ani vyhľadávač.
- **Veľkostná tabuľka** je natívny `<dialog>` s `aria-label` a tlačidlom
  zavrieť s `aria-label`; dekoratívne ikony `aria-hidden`.
- **Waitlist** hlási výsledok odoslania v `role="status" aria-live="polite"`.
- **Firemné údaje** prepínajú blok cez atribút `hidden` (nie len CSS), takže
  skryté polia sú skryté aj pre čítačku a nepýta sa na ne validácia.
- Formulárové polia idú cez WooCommerce `woocommerce_form_field`, teda
  s `<label>` a chybovými hláškami pokladne.
- Texty sú preložiteľné (`languages/px-shop-core-sk_SK.po`).

Kontrastné pomery, veľkosť cieľov a viditeľnosť fokusu sú vec témy
(`px-shop-theme`, tokeny `--pc-*`).

## WP-CLI

| Príkaz | Na čo |
| --- | --- |
| `wp px wishlist migrate [--dry-run]` | prevod obľúbených z Woodmartu (`woodmart_wishlists` → user meta, idempotentné) |
| `wp px waitlist migrate [--dry-run]` | prevod `<prefix>woodmart_waitlists` (aj `variation_id`) |
| `wp px size-guide migrate [--dry-run]` | prevod veľkostných príručiek, priradení na produktoch a kategóriách |

Príkazy sa registrujú len pri zapnutom module.

## Page cache

Plugin cache nemá, ale dve veci si s ňou vybaviť musí. Všetko voči WP Rocketu
ide cez `function_exists()`, takže na webe bez neho je to tichý no-op.

| Funkcia | Na čo |
| --- | --- |
| `px_shop_core_no_page_cache()` | `DONOTCACHEPAGE` + `nocache_headers()` (len ak hlavičky ešte neodišli). Volá sa v `[px_wishlist]`, `[px_compare]` a pri predvyplnenom e-maile vo waitliste |
| `px_shop_core_purge_page_cache()` | objedná `rocket_clean_domain()` na `shutdown`, raz za request. Volá sa pri zmene bannera (`px_content`), veľkostnej tabuľky (`px_size_guide`) a jej priradenia ku kategórii |
| akcia `px_shop_core_purge_page_cache` | miesto pre ostatné cache webu (LiteSpeed, Varnish, Cloudflare) — patrí do site pluginu, nie sem |

Neverejné post typy (`public => false`) sú pre `rocket_clean_post()` neviditeľné
— preto plný purge domény. Used CSS sa nemaže: regeneruje sa pre celý web a
text bannera oň nezavadí. Po zmene rozloženia bannera (nové CSS triedy) ho
treba pretlačiť ručne.

Modul, ktorý si plánuje cron, ho deklaruje v registri kľúčom `cron`. Vypnutý
modul si udalosť upratuje sám (kontrola beží len v administrácii), deaktivácia
pluginu ju zruší tiež.

## Konvencie pre tému

- Dostupnosť funkcie: `class_exists( 'PX_Xyz' )` (pri súhlase
  `PX_Consent::active()`).
- Markup pluginu je neutrálny `px-*`; komponenty `.pc-scope`, tokeny a CSS
  patria do `px-shop-theme` / child témy.
- Šablóny e-mailov a súhlasu sa prepisujú v téme
  (`woocommerce/emails/px-waitlist-*.php`, `px-shop-core/consent/*.php`).
- Nikdy nečítať súhlas ani stav košíka v PHP pri výstupe, ktorý ide do page
  cache — plugin to sám nerobí a téma by to nemala kaziť. Keď to inak nejde,
  `px_shop_core_no_page_cache()` (viď [Page cache](#page-cache)).
