<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Site Converter', 'fw' );
$manifest['slug']        = 'unysonplus-site-converter';
$manifest['description'] = __(
	'Bring an AI-generated website into WordPress. Imports media, styling presets, theme settings, pages and menus — one at a time or as a one-shot "Convert bundle" — and can generate a matching header/footer theme. Open the Convert page for the full toolkit.',
	'fw'
);

$manifest['version']       = '1.2.8';
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Site-Converter-Extension';
$manifest['display']       = true;
$manifest['standalone']    = true;

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
