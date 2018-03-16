<?php

namespace Livy\Plumbing\Preload;

/**
 * Enqueue a script to be preloaded.
 *
 * @param string $name
 * @param string $path
 */
function register_preloaded_script(string $name, string $path)
{
    add_action('wp_enqueue_scripts', function () use ($name, $path) {
        wp_enqueue_style(
            $name,
            $path,
            false,
            null
        );
    });

    add_filter('plumbing-preload/enqueued/scripts', function ($enqueued) use ($name, $path) {
        $enqueued[$name] = $path;
        return $enqueued;
    });
}

function register_preloaded_style(string $name, string $path, boolean $lazy_load = false)
{
    add_action('wp_enqueue_scripts', function () use ($name, $path) {
        wp_enqueue_script(
            'artemesia/preload/sage/main.js',
            asset_path('scripts/main.js'),
            ['jquery'],
            null,
            true
        );
    });

    add_filter('plumbing-preload/enqueued/styles', function ($enqueued) use ($name, $path) {
        $enqueued[$name] = $path;
        return $enqueued;
    });

    if (true === $lazy_load) {
        add_filter('plumbing-preload/enqueued/styles/lazy', function ($enqueued) use ($name, $path) {
            $enqueued[$name] = $path;
            return $enqueued;
        });
    }
}

/**
 * Generate the name for our CSS cache-tracking cookies.
 *
 * @param string $name
 * @return string
 */
function get_css_cookie_name(string $name)
{
    return sprintf("CSS_CACHED_%s", strtoupper($name));
}

/**
 * Set a cookie so we can guess whether we've loaded
 * css or not.
 *
 * Only fires for scripts that have been passed `true` for lazy-loading.
 */
add_action('init', function () {
    $lazy_styles = apply_filters('plumbing-preload/enqueued/styles/lazy', []);
    if (count($lazy_styles) > 0) {
        foreach ($lazy_styles as $name => $path) {
            $css_id      = hash('md4', asset_path($path));
            $cookie_name = get_css_cookie_name($name);
            if (!isset($_COOKIE[$cookie_name])) {
                // If the cookie isn't set, set it.
                setcookie($cookie_name, $css_id, strtotime('+30 days'), '/');
            } elseif ($_COOKIE[$cookie_name] != $css_id) {
                // If the cookie doesn't match our CSS, unset it.
                setcookie($cookie_name, $_COOKIE[$cookie_name], 1, '/');
                unset($_COOKIE[$cookie_name]);
            }
            unset($css_id, $cookie_name);
        }
    }
});

/**
 * Preloads any scripts that we have asked to
 * preload.
 */
add_action('wp_head', function () {
    $enqueued = apply_filter('plumbing-preload/enqueued/scripts', []);
    foreach (wp_scripts()->registered as $handle => $script) {
        if (isset($enqueued[$handle])) {
            printf('<link rel="preload" href="%s" as="script">', apply_filters('script_loader_src', $script->src));
        }
    }
});

/**
 * Preloads any styles that we have asked to preload.
 */
add_filter('style_loader_tag', function ($html, $handle, $href, $media) {
    $enqueued_styles = apply_filters('plumbing-preload/enqueued/styles', []);
    $lazy_styles     = apply_filters('plumbing-preload/enqueued/styles/lazy', []);

    if (isset($enqueued_styles[$handle])) {
        $url = apply_filters('style_loader_src', $href, $handle);
        if (isset($lazy_styles[$handle]) && !isset($_COOKIE[get_css_cookie_name($handle)])) {
            // @codingStandardsIgnoreStart
            return sprintf(
                '<link rel="preload" href="%1$s" as="style" type="text/css" onload="this.rel=\'stylesheet\'" media="%2$s">%3$s<noscript><link rel="stylesheet" href="%1$s"></noscript>',
                $url,
                $media,
                PHP_EOL
            );
            // @codingStandardsIgnoreEnd
        } else {
            return sprintf('<link rel="preload" href="%1$s" as="style">', $url);
        }
    }

    return $html;
}, 10, 4);

/**
 * If we're lazy-loading any styles, add JS to the footer to enable that.
 *
 * @see https://github.com/filamentgroup/loadCSS
 */
add_action('wp_footer', function () {
    // Only fire this if we're lazy-loading styles.
    if (count(apply_filters('plumbing-preload/enqueued/styles/lazy', [])) > 0) {
        /**
         * There isn't a good way to break up these strings, so we're
         * going to ignore standards for a bit.
         */
        // @codingStandardsIgnoreStart
        $loadCSS         = '!function(a){"use strict";var b=function(b,c,d){function j(a){if(e.body)return a();setTimeout(function(){j(a)})}function l(){f.addEventListener&&f.removeEventListener("load",l),f.media=d||"all"}var g,e=a.document,f=e.createElement("link");if(c)g=c;else{var h=(e.body||e.getElementsByTagName("head")[0]).childNodes;g=h[h.length-1]}var i=e.styleSheets;f.rel="stylesheet",f.href=b,f.media="only x",j(function(){g.parentNode.insertBefore(f,c?g:g.nextSibling)});var k=function(a){for(var b=f.href,c=i.length;c--;)if(i[c].href===b)return a();setTimeout(function(){k(a)})};return f.addEventListener&&f.addEventListener("load",l),f.onloadcssdefined=k,k(l),f};"undefined"!=typeof exports?exports.loadCSS=b:a.loadCSS=b}("undefined"!=typeof global?global:this);';
        $preloadPolyfill = '!function(a){if(a.loadCSS){var b=loadCSS.relpreload={};if(b.support=function(){try{return a.document.createElement("link").relList.supports("preload")}catch(a){return!1}},b.poly=function(){for(var b=a.document.getElementsByTagName("link"),c=0;c<b.length;c++){var d=b[c];"preload"===d.rel&&"style"===d.getAttribute("as")&&(a.loadCSS(d.href,d,d.getAttribute("media")),d.rel=null)}},!b.support()){b.poly();var c=a.setInterval(b.poly,300);a.addEventListener&&a.addEventListener("load",function(){b.poly(),a.clearInterval(c)}),a.attachEvent&&a.attachEvent("onload",function(){a.clearInterval(c)})}}}(this);';
        printf(
            '<!-- Start loadCSS scripts -->%2$s<script type="text/javascript" charset="utf-8">%1$s%2$s%3$s</script>%2$s<!-- End loadCSS scripts -->',
            $loadCSS,
            PHP_EOL,
            $preloadPolyfill
        );
        // @codingStandardsIgnoreEnd
    }
}, 99);
