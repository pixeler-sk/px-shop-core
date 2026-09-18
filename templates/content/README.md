# Bannery z CPT Content

Obsahové bloky (bannery, promo pásy, CTA) sa v admine spravujú v **Content**
(CPT `px_content`, modul *Content blocks*). Plugin drží dáta, layouty
a neutrálny markup; **vzhľad patrí téme**.

## Čo je kde

| Údaj | Kde sa vypĺňa |
| --- | --- |
| Nadpis | Titulok položky |
| Perex | Zhrnutie príspevku (Excerpt) |
| Voľný text | Editor |
| Obrázok | Náhľadový obrázok |
| Nadtitulok, layout, zarovnanie, stmavenie, dve tlačidlá | Box **Banner display** |
| Video na pozadí, „Prehrávať aj na mobile", odkaz celého bannera | Box **Banner display** (1.10.0) |
| Skupina (kam banner patrí) | Taxonómia **Kategórie obsahu** |
| Poradie v skupine | `menu_order` (Atribúty stránky → Poradie) |

## Vloženie do webu

```php
px_banners( 'uvodka-hero' );                       // celá kategória
px_banners( [ 'category' => 'promo', 'columns' => 3 ] );
px_banner( 5098, [ 'heading_tag' => 'h1', 'eager' => true ] );
```

```
[px_banner id="5098"]
[px_banner slug="jarna-akcia" layout="background"]
[px_banner category="promo" columns="3"]
```

```php
// Hero karusel na úvodke
px_banners( [
	'category'    => 'uvodka-hero',
	'carousel'    => true,
	'heading_tag' => 'h1',
	'eager'       => true,
	'image_size'  => 'full',
	'image_sizes' => '100vw',
	'class'       => 'px-banner--hero',
] );
```

```
[px_banner category="uvodka-hero" carousel="1"]
```

Argumenty: `layout` (vynúti layout), `class` (na každom banneri),
`heading_tag`, `image_size`, `image_sizes` (atribút `sizes`, banner cez celú
šírku = `100vw`), `eager` (bez lazy loadingu + `fetchpriority="high"`),
`limit`, `columns`, `wrap`, `carousel`, `label` (prístupný názov karuselu),
`group_class` (trieda na obale `.px-banners`).

V skupine dostane `heading_tag` h1 **len prvý banner**, ostatné h2 — stránka
má jeden hlavný nadpis. Iné úrovne (h3 v dlaždiciach) platia pre všetky.

Prázdna kategória vráti `''` — sekcia sa v šablóne jednoducho nevykreslí,
takže sa dá volať bezpodmienečne.

## Layouty

| id | Šablóna | Používa |
| --- | --- | --- |
| `media-right` | `banner-split.php` | obrázok, nadtitulok, zarovnanie, tlačidlá |
| `media-left` | `banner-split.php` | to isté, obrázok vľavo |
| `background` | `banner-background.php` | + stmavenie obrázka |
| `plain` | `banner.php` | bez obrázka, bez videa |

`supports` kľúče: `image`, `eyebrow`, `align`, `overlay`, `buttons`,
`video` (media-right, media-left, background), `link` (všetky štyri).
Vlastný layout projektu ich musí uviesť výslovne a jeho šablóna musí
vypísať party `parts/banner-video.php` a `parts/banner-link.php`
(nadpis s odkazom rieši `parts/banner-text.php`).

Polia, ktoré layout nepoužíva (`supports`), sa v admine skryjú a pri
renderovaní sa ignorujú — layout bez `image` nevykreslí náhľadový obrázok,
ani keď ho položka má.

## Vlastný layout pre konkrétny web

1. Zaregistruj layout v site plugine alebo v `functions.php` témy:

```php
add_filter( 'px_content_layouts', function ( array $layouts ): array {
	$layouts['akciovy-pas'] = [
		'label'    => 'Akciový pás',
		'template' => 'content/banner-akciovy-pas.php',
		'supports' => [ 'image', 'eyebrow', 'buttons' ],
	];

	return $layouts;
} );
```

2. Ulož šablónu do témy: `themes/<tema>/px-shop-core/content/banner-akciovy-pas.php`
   (child téma má prednosť pred parentom).
3. Štýly daj do CSS témy. Wrapper už nesie triedy
   `px-banner px-banner--akciovy-pas px-banner--align-*`.

Rovnakou cestou sa prepíše **ktorákoľvek** šablóna pluginu — stačí súbor
s rovnakým názvom v `themes/<tema>/px-shop-core/content/`.

## Video na pozadí

Polia v boxe **Banner display**:

| Pole | Meta | Poznámka |
| --- | --- | --- |
| Video (YouTube / Vimeo) | `_px_banner_video_url` | akýkoľvek tvar odkazu: `watch?v=`, `youtu.be/`, `embed/`, `shorts/`, `live/`, s parametrami (`?si=`, `&t=`); Vimeo `vimeo.com/ID`, neverejné `vimeo.com/ID/HASH`, `player.vimeo.com/video/ID?h=HASH`. Nerozpoznaná adresa sa uloží, box pri nej ukáže varovanie a banner kreslí len obrázok. |
| Videosúbor (MP4 / WebM) | `_px_banner_video_id` | príloha z knižnice médií; iný typ sa pri uložení zahodí. Má prednosť pred URL — nepotrebuje súhlas ani požiadavku na tretiu stranu. |
| Prehrávať aj na mobile | `_px_banner_video_mobile` | predvolene vypnuté — pod 768 px ostáva obrázok |

`px_get_banner()` vracia `video` (prázdne pole, alebo `type`
youtube|vimeo|file, `id`, `hash`, `src`, `mime`, `mobile`; pri renderovaní
navyše `consent`) a `link`. Parsovanie odkazu: `PX_Content::parse_video_url()`.

**Do HTML nejde iframe.** Iframe YouTube v markupe by bol požiadavkou na
Google pred súhlasom, embed blocker consent modulu by z neho spravil
placeholder „zobraziť video" a pri načítaní by súperil s LCP obrázkom.
Plugin kreslí len prázdny wrapper s dátami; prehrávač stavia téma
(handle `px-banner-video`, plugin ho vyžiada iba keď sa vykreslí banner
s videom). Pri súbore je vo wrapperi `<video muted loop playsinline
preload="none">` — kým ho skript nespustí, nestiahne sa nič. Atribút
`poster` nemá: posterom je `<img>` bannera pod ním (so srcset), druhá kópia
by bola požiadavka navyše.

**Súhlas.** YouTube/Vimeo čaká na súhlas, súbor z vlastnej domény nie.
Kto odpovedá, určí `PX_Content::video_consent()` a zapíše do wrappera
(`data-px-consent-cmp`, `data-px-consent-category`, `data-px-consent-service`):

1. **consent modul px-shop-core aktívny** (`PX_Consent::active()`) → `px`,
   služba youtube / vimeo a jej kategória (inak `marketing`); téma pýta
   `window.pxConsent.allowed( category, service )`, čaká na `px:consent`,
2. **inak CookieYes** → `cookieyes`, kategória `advertisement` (filter
   `px_content_video_cmp_category`); téma pýta `getCkyConsent().categories`,
   čaká na `cookieyes_banner_load` / `cookieyes_consent_update`
   (`detail.accepted`). CookieYes sa zistí podľa pluginu cookie-law-info
   (`CLI_VERSION`); web, ktorý CookieYes načítava z GTM, vráti `'cookieyes'`
   filtrom `px_content_video_cmp` (libike),
3. **inak** bez atribútov — video hrá hneď (téma sa ešte pozrie, či na
   stránke nie je `getCkyConsent`).

Bez súhlasu ostáva obrázok, žiadny placeholder — video je výzdoba. Celý
výsledok mení filter `px_content_video_consent`.

**Pauza (WCAG 2.2.2).** Video v karuseli pauzuje tlačidlo karuselu;
samostatný banner s videom dostane od témy vlastné tlačidlo pauza/prehrať.
Wrapper videa je `inert` a `aria-hidden`.

**Téma < 0.6.0.** Plugin kreslí video len keď je registrovaný handle
`px-banner-video` (filter `px_content_video_script_handle`) a karusel len
s `px-banner-carousel` — inak ostáva obrázok a skupina bannerov pod sebou.

Obrázok bannera ostáva posterom aj LCP prvkom — položka s videom má mať
náhľadový obrázok (bez neho je do spustenia videa prázdny/tmavý banner).

## Odkaz celého bannera

`_px_banner_link_url` (pole **Odkaz celého bannera**). Nadpis sa stane
odkazom (`.px-banner__title > a.px-banner__link`) a téma jeho `::after`
roztiahne cez celý banner (stretched link). Tlačidlá ostávajú vlastnými
odkazmi — nič sa nevnára do `<a>`. Banner bez nadpisu dostane prázdny
`a.px-banner__link--bare` s `aria-label` priamo v sekcii.
Wrapper má triedu `px-banner--linked`.

## Karusel

`carousel => true` (shortcode `carousel="1"`): pri viac než jednej položke
je skupina Swiper markup. Jedna položka ostáva obyčajným bannerom.

- Ovládanie (šípky, bodky, pauza/prehrať) je v markupe s `hidden` —
  bez JS nie je vidno nefunkčné tlačidlá; téma ho odkryje po štarte.
- Prvý slide je `eager` s `fetchpriority="high"`, druhý `eager` bez
  priority, ďalšie lazy. Mimo karuselu má `eager` len prvý banner skupiny.
- Plugin vyžiada handle `px-banner-carousel` (štýl aj skript, ak ich téma
  registruje).
- Texty ovládania: filter `px_content_carousel_labels` (kľúče `region`,
  `role`, `slide`, `prev`, `next`, `pause`, `play`, `dots`) — stačí vrátiť
  kľúče, ktoré sa menia, zvyšok ostane predvolený.

## Kontrakt markupu

```html
<section class="px-banner px-banner--{layout} px-banner--align-{align}
                [px-banner--has-video] [px-banner--linked] [px-banner--no-image]">
  <a class="px-banner__link px-banner__link--bare" aria-label="…">  <!-- len odkaz bez nadpisu -->
  <img class="px-banner__bg">            <!-- len background -->
  <div class="px-banner__video"          <!-- background: tu; split: v __media -->
       data-px-banner-video="youtube|vimeo|file"
       data-px-video-id="…" data-px-video-hash="…" data-px-video-title="…"
       data-px-video-mobile="0|1"
       data-px-consent-cmp="px|cookieyes" data-px-consent-category="…"
       data-px-consent-service="youtube"
       aria-hidden="true" inert>
    <video class="px-banner__video-media" muted loop playsinline preload="none"><source></video>  <!-- len file -->
  </div>
  <div class="px-banner__inner">
    <div class="px-banner__text">
      <p class="px-banner__eyebrow">
      <h2 class="px-banner__title"><a class="px-banner__link"></a></h2>  <!-- a len s odkazom -->
      <p class="px-banner__perex">
      <div class="px-banner__content">   <!-- text z editora -->
      <div class="px-banner__actions">
    </div>
    <div class="px-banner__media"><img class="px-banner__image"><div class="px-banner__video"></div></div>
  </div>
</section>
```

Skupina je obalená v `<div class="px-banners px-banners--cols-N">`.

Karusel:

```html
<div class="px-banners px-banners--carousel swiper" data-px-banners-carousel
     role="region" aria-roledescription="karusel" aria-label="Bannery">
  <div class="px-banners__controls" hidden>
    <button class="px-banners__btn px-banners__prev">
    <div class="px-banners__dots">       <!-- bodky kreslí Swiper -->
    <button class="px-banners__btn px-banners__next">
    <button class="px-banners__btn px-banners__toggle" data-label-pause data-label-play>
  </div>
  <div class="px-banners__track swiper-wrapper">
    <div class="px-banners__slide swiper-slide" role="group" aria-roledescription="snímka" aria-label="1 z 3">
      <section class="px-banner …">
    </div>
  </div>
</div>
```

Téma pri zmene slidu posiela udalosť `px:banners-slide` (bubbles), podľa
ktorej sa video spúšťa len na aktívnom slide.

## Hooky

| Hook | Načo |
| --- | --- |
| `px_content_layouts` | register layoutov |
| `px_content_default_layout` | layout položky bez voľby |
| `px_content_banner_data` | úprava dát položky |
| `px_content_banner_classes` | triedy wrappera |
| `px_content_banner_html` | hotové HTML bannera |
| `px_content_button_class` | triedy tlačidiel (téma ich mapuje na svoj komponent) |
| `px_content_text_html` | text z editora po filtroch |
| `px_content_locate_template` | cesta k šablóne |
| `px_content_style_handle` | handle štýlu, ktorý si plugin vypýta od témy (default `px-banner`) |
| `px_content_video_script_handle` | handle skriptu videa na pozadí (default `px-banner-video`), vyžiada sa len pri banneri s videom |
| `px_content_video_consent` | súhlas, na ktorý video čaká (`cmp`, `category`, `service`; prázdne pole = hneď) |
| `px_content_video_cmp` | externý CMP, keď consent modul nebeží (`cookieyes` / `''`; default podľa pluginu cookie-law-info) |
| `px_content_video_cmp_category` | kategória CookieYes pre video (default `advertisement`) |
| `px_content_carousel_handle` | handle štýlu + skriptu karuselu (default `px-banner-carousel`) |
| `px_content_carousel_labels` | texty ovládania karuselu |

## Poznámky

- Plugin assety nevlastní: handles (`px-banner`, `px-banner-video`,
  `px-banner-carousel`) registruje téma; keď ich téma nemá, banner sa
  vykreslí, video ostane obrázkom a slidy karuselu budú pod sebou (bez
  Swiper CSS nie je čo ich uložiť vedľa seba; ovládanie ostane skryté).

- Položky Content sú **dáta** — pri nasadení na ďalšie prostredie musia
  vzniknúť znova alebo sa premigrovať; téma bez nich vykreslí prázdno.
- Blokový editor odkladá box **Banner display** do zásuvky *Meta bloky*
  v spodku obrazovky. Web, ktorý má radšej klasický editor, si ho zapne
  natívnym filtrom:
  `add_filter( 'use_block_editor_for_post_type', fn( $on, $type ) => 'px_content' === $type ? false : $on, 10, 2 );`
