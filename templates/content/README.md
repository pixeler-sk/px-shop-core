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

Argumenty: `layout` (vynúti layout), `class`, `heading_tag`, `image_size`,
`eager`, `limit`, `columns`, `wrap`.

Prázdna kategória vráti `''` — sekcia sa v šablóne jednoducho nevykreslí,
takže sa dá volať bezpodmienečne.

## Layouty

| id | Šablóna | Používa |
| --- | --- | --- |
| `media-right` | `banner-split.php` | obrázok, nadtitulok, zarovnanie, tlačidlá |
| `media-left` | `banner-split.php` | to isté, obrázok vľavo |
| `background` | `banner-background.php` | + stmavenie obrázka |
| `plain` | `banner.php` | bez obrázka |

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

## Kontrakt markupu

```html
<section class="px-banner px-banner--{layout} px-banner--align-{align}">
  <img class="px-banner__bg">            <!-- len background -->
  <div class="px-banner__inner">
    <div class="px-banner__text">
      <p class="px-banner__eyebrow">
      <h2 class="px-banner__title">
      <p class="px-banner__perex">
      <div class="px-banner__content">   <!-- text z editora -->
      <div class="px-banner__actions">
    </div>
    <div class="px-banner__media"><img class="px-banner__image"></div>
  </div>
</section>
```

Skupina je obalená v `<div class="px-banners px-banners--cols-N">`.

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

## Poznámky

- Položky Content sú **dáta** — pri nasadení na ďalšie prostredie musia
  vzniknúť znova alebo sa premigrovať; téma bez nich vykreslí prázdno.
- Blokový editor odkladá box **Banner display** do zásuvky *Meta bloky*
  v spodku obrazovky. Web, ktorý má radšej klasický editor, si ho zapne
  natívnym filtrom:
  `add_filter( 'use_block_editor_for_post_type', fn( $on, $type ) => 'px_content' === $type ? false : $on, 10, 2 );`
