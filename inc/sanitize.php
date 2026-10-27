<?php
/* Cleans article HTML written in the dashboard: keeps simple formatting, removes anything that could run code. */

if (!defined('SITE_APP')) { http_response_code(404); exit; }

const SAN_ALLOWED = [
  'p' => [], 'br' => [], 'h2' => ['id'], 'h3' => ['id'], 'h4' => [], 'strong' => [], 'em' => [], 'u' => [], 's' => [],
  'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [], 'code' => [], 'pre' => [],
  'a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'], 'figure' => [], 'figcaption' => [],
  'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
];
const SAN_RENAME = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'strike' => 's', 'del' => 's'];
const SAN_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'link', 'meta', 'noscript', 'template', 'video', 'audio', 'canvas'];
const SAN_BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'figure', 'table', 'pre', 'hr'];

function sanitize_html($html) {
  $html = trim((string) $html);
  if ($html === '') return '';
  $doc = new DOMDocument();
  $prev = libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="UTF-8"><div id="san-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  $root = $doc->getElementById('san-root');
  if (!$root) return '';
  san_walk($doc, $root);

  $out = '';
  foreach ($root->childNodes as $n) $out .= $doc->saveHTML($n);
  $out = preg_replace('~<p>(\s|&nbsp;|<br>)*</p>~u', '', $out);
  return trim($out);
}

function san_unwrap(DOMDocument $doc, DOMNode $parent, DOMNode $el) {
  san_walk($doc, $el);
  while ($el->firstChild) $parent->insertBefore($el->firstChild, $el);
  $parent->removeChild($el);
}

function san_walk(DOMDocument $doc, DOMNode $node) {
  // copy the list first: we change the tree while walking it
  $kids = [];
  foreach ($node->childNodes as $c) $kids[] = $c;
  foreach ($kids as $c) {
    if ($c->nodeType === XML_TEXT_NODE) continue;
    if ($c->nodeType !== XML_ELEMENT_NODE) { $node->removeChild($c); continue; }
    $tag = strtolower($c->nodeName);
    if (in_array($tag, SAN_DROP, true)) { $node->removeChild($c); continue; }

    if ($tag === 'div') {                       // editors make <div> lines: keep them as paragraphs
      $hasBlock = false;
      foreach ($c->childNodes as $g) if ($g->nodeType === XML_ELEMENT_NODE && in_array(strtolower($g->nodeName), SAN_BLOCKS, true)) $hasBlock = true;
      $tag = $hasBlock ? null : 'p';
    } elseif (isset(SAN_RENAME[$tag])) {
      $tag = SAN_RENAME[$tag];
    } elseif (!isset(SAN_ALLOWED[$tag])) {
      $tag = null;                              // span, font, … → keep the text, drop the tag
    }
    if ($tag === null) { san_unwrap($doc, $node, $c); continue; }

    if ($tag !== strtolower($c->nodeName)) {
      $new = $doc->createElement($tag);
      foreach ($c->attributes as $a) $new->setAttribute($a->name, $a->value);
      while ($c->firstChild) $new->appendChild($c->firstChild);
      $node->replaceChild($new, $c);
      $c = $new;
    }

    $keep = SAN_ALLOWED[$tag];
    $attrs = [];
    foreach ($c->attributes as $a) $attrs[] = $a->name;
    foreach ($attrs as $name) if (!in_array(strtolower($name), $keep, true)) $c->removeAttribute($name);

    if ($tag === 'a') {
      $href = san_url($c->getAttribute('href'), true);
      if ($href === null) { san_unwrap($doc, $node, $c); continue; }
      $c->setAttribute('href', $href);
      if (preg_match('~^https?://~i', $href) && stripos($href, base_url()) !== 0) {
        $c->setAttribute('target', '_blank');
        $c->setAttribute('rel', 'noopener');
      }
    }
    if ($tag === 'img') {
      $src = san_url($c->getAttribute('src'), false);
      if ($src === null) { $node->removeChild($c); continue; }
      $c->setAttribute('src', $src);
      $c->setAttribute('loading', 'lazy');
      $c->setAttribute('decoding', 'async');
      if (!$c->hasAttribute('alt')) $c->setAttribute('alt', '');
      foreach (['width', 'height'] as $d) if ($c->hasAttribute($d) && !ctype_digit($c->getAttribute($d))) $c->removeAttribute($d);
      continue;
    }
    if (($tag === 'h2' || $tag === 'h3') && $c->hasAttribute('id') && !preg_match('/^[\p{L}\p{N}_-]{1,80}$/u', $c->getAttribute('id'))) $c->removeAttribute('id');
    san_walk($doc, $c);
  }
}

// Allowed link targets: http(s), site-relative, #anchors, mailto/tel (links only). Images: http(s) or site-relative.
function san_url($u, $isLink) {
  $u = str_replace(' ', '%20', trim((string) $u));
  $u = preg_replace('/[\x00-\x1f\x7f]+/', '', $u);   // "java\nscript:" tricks
  if ($u === '') return null;
  if (preg_match('~^https?://~i', $u)) return $u;
  if ($isLink && preg_match('~^(mailto:|tel:|#)~i', $u)) return $u;
  if (preg_match('~^/(?!/)~', $u)) return $u;
  if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $u) && strpos($u, '//') !== 0 && strpos($u, '\\') === false) return '/' . ltrim($u, './');
  return null;
}

// Give each H2/H3 an id so the table of contents and "jump to" links work.
function add_heading_ids($html, &$toc) {
  $toc = [];
  $used = [];
  return preg_replace_callback('~<(h2|h3)([^>]*)>(.*?)</\1>~su', function ($m) use (&$toc, &$used) {
    $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
    if ($text === '') return $m[0];
    if (preg_match('/\sid="([^"]+)"/', $m[2], $idm)) $id = $idm[1];
    else { $id = slugify($text) ?: 'section'; $base = $id; $i = 2; while (isset($used[$id])) $id = $base . '-' . $i++; }
    $used[$id] = true;
    $toc[] = ['level' => $m[1], 'id' => $id, 'text' => $text];
    $attrs = preg_replace('/\sid="[^"]*"/', '', $m[2]);
    return '<' . $m[1] . $attrs . ' id="' . e($id) . '">' . $m[3] . '</' . $m[1] . '>';
  }, $html);
}
