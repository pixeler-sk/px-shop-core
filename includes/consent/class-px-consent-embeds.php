<?php
/**
 * Embedded content that would call a third party before consent.
 *
 * A YouTube or Maps iframe is a request to Google the moment the page
 * renders - no script is involved, so the `type="text/plain"` contract
 * cannot help. The iframe is therefore taken out of the document and parked
 * in a <template> (inert by definition, the browser fetches nothing) behind
 * a placeholder with a button. One click allows that one service, not the
 * whole marketing category, and the iframe is put back in place.
 *
 * YouTube is switched to youtube-nocookie.com: once the visitor allows it,
 * the extended cookies are still not needed for playback.
 *
 * The placeholder keeps a plain link to the original address, so the content
 * stays reachable without JavaScript and a crawler still sees where it leads.
 *
 * @package PxShopCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PX_Consent_Embeds {

	/** Marks content that has already been through the blocker. */
	const TEMPLATE_CLASS = 'px-consent-embed__template';

	/**
	 * Host fragments mapped to the service that owns them.
	 *
	 * A key wrapped in # is a regular expression: Google runs maps on every
	 * country domain it owns (google.sk, google.co.uk), and listing them one
	 * by one would miss the next one.
	 *
	 * @return array
	 */
	public static function hosts() {
		/**
		 * Filters the host => service map used to recognise embeds.
		 *
		 * @param array $hosts Host fragment (or #regex#) => service id.
		 */
		return apply_filters(
			'px_consent_embed_hosts',
			array(
				'youtube.com'                           => 'youtube',
				'youtube-nocookie.com'                  => 'youtube',
				'youtu.be'                              => 'youtube',
				'#//(www\.)?google\.[a-z.]{2,7}/maps#i' => 'google_maps',
				'maps.google.'                          => 'google_maps',
			)
		);
	}

	/**
	 * Which service an embed URL belongs to.
	 *
	 * @param string $url Address from the iframe.
	 * @return string Service id, '' when it is none of ours.
	 */
	private static function service_for_url( $url ) {
		foreach ( self::hosts() as $needle => $service ) {
			$needle = (string) $needle;

			if ( '#' === substr( $needle, 0, 1 ) ) {
				if ( preg_match( $needle, $url ) ) {
					return $service;
				}

				continue;
			}

			if ( false !== stripos( $url, $needle ) ) {
				return $service;
			}
		}

		return '';
	}

	public static function init() {
		add_filter( 'embed_oembed_html', array( __CLASS__, 'filter' ), 20 );
		add_filter( 'the_content', array( __CLASS__, 'filter' ), 20 );
		add_filter( 'widget_text_content', array( __CLASS__, 'filter' ), 20 );

		/**
		 * Lets a theme or a site plugin run its own markup through the same
		 * blocker: apply_filters( 'px_consent_embeds', $html ).
		 */
		add_filter( 'px_consent_embeds', array( __CLASS__, 'filter' ) );
	}

	/**
	 * Replaces blocked iframes in a piece of HTML.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	public static function filter( $html ) {
		$html = (string) $html;

		// A feed reader cannot press the button, the admin is not the place to
		// block anything, and a placeholder without the consent script on the
		// page would hide the content from everybody.
		if ( is_feed() || ! PX_Consent::enabled_here() || false === strpos( $html, '<iframe' ) ) {
			return $html;
		}

		// The same content passes through here twice: oEmbed markup is
		// filtered as it is resolved (embed_oembed_html, inside the_content at
		// priority 8) and then again as part of the whole post. Iframes that
		// are already parked in a <template> are taken out of the way first,
		// otherwise the second pass would wrap a placeholder in a placeholder
		// and the click would reveal the inner box instead of the video.
		$stash = array();

		if ( false !== strpos( $html, self::TEMPLATE_CLASS ) ) {
			$html = (string) preg_replace_callback(
				'#<template\b[^>]*class="[^"]*' . preg_quote( self::TEMPLATE_CLASS, '#' ) . '[^"]*"[^>]*>.*?</template>#is',
				static function ( $match ) use ( &$stash ) {
					$key           = '<!--px-consent-embed-' . count( $stash ) . '-->';
					$stash[ $key ] = $match[0];

					return $key;
				},
				$html
			);
		}

		$html = (string) preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>#is',
			array( __CLASS__, 'replace' ),
			$html
		);

		return $stash ? strtr( $html, $stash ) : $html;
	}

	/**
	 * One iframe.
	 *
	 * @param array $match Regex match.
	 * @return string
	 */
	private static function replace( $match ) {
		$iframe = $match[0];

		if ( ! preg_match( '#\ssrc=["\']([^"\']+)["\']#i', $iframe, $src ) ) {
			return $iframe;
		}

		$url     = html_entity_decode( $src[1], ENT_QUOTES, 'UTF-8' );
		$service = self::service_for_url( $url );

		// Unknown embed, or the shop does not block this service - the iframe
		// is left exactly as the editor wrote it.
		if ( '' === $service || ! PX_Consent_Services::is_enabled( $service ) ) {
			return $iframe;
		}

		if ( 'youtube' === $service ) {
			$nocookie = self::youtube_nocookie( $url );
			$iframe   = str_replace( $src[1], esc_url( $nocookie ), $iframe );
			$url      = $nocookie;
		}

		$data = PX_Consent_Services::get( $service );

		$html = PX_Consent::get_template(
			'consent/embed.php',
			array(
				'service'    => $service,
				'name'       => isset( $data['name'] ) ? $data['name'] : $service,
				'purpose'    => isset( $data['purpose'] ) ? $data['purpose'] : '',
				'url'        => $url,
				'embed_html' => $iframe,
			)
		);

		return '' !== $html ? $html : $iframe;
	}

	/**
	 * The same video on the domain that stores less.
	 *
	 * A youtu.be link inside an iframe is not a player URL, so it is turned
	 * into one instead of having its host swapped - the browser would
	 * otherwise ask youtube-nocookie.com for a page that does not exist.
	 *
	 * @param string $url Address from the iframe.
	 * @return string
	 */
	private static function youtube_nocookie( $url ) {
		if ( preg_match( '~^(https?:)?//(www\.)?youtu\.be/([^?&/#]+)(.*)$~i', $url, $short ) ) {
			$query = '';

			if ( isset( $short[4] ) && false !== strpos( $short[4], '?' ) ) {
				$query = substr( $short[4], strpos( $short[4], '?' ) );
			}

			return 'https://www.youtube-nocookie.com/embed/' . $short[3] . $query;
		}

		return str_ireplace( array( 'www.youtube.com', 'youtube.com' ), 'www.youtube-nocookie.com', $url );
	}
}
