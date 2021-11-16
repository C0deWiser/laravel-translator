<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Polyglot Switch
    |--------------------------------------------------------------------------
    |
    | Disabled Polyglot provides Artisan console command to extract translation
    | strings and web panel to manage translations.
    |
    | Enabled Polyglot replaces Laravel Translator service, bringing Gettext
    | support to the Application. With full backward compatability.
    |
    */

    'enabled' => env('POLYGLOT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Application Locales Configuration
    |--------------------------------------------------------------------------
    |
    | The application locales determines the listing of locales that will be used
    | by Polyglot to populate collected translation strings across locales.
    |
    */

    'locales' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Polyglot Extractor Configuration
    |--------------------------------------------------------------------------
    |
    | Extractor parses source codes, finding translation strings.
    |
    | 'xgettext`    - extracts translation strings from source codes
    |                 using the power of xgettext utility.
    |
    */

    'extractor' => 'xgettext',

    /*
    |--------------------------------------------------------------------------
    | Polyglot Producer Configuration
    |--------------------------------------------------------------------------
    |
    | After extracting, producer populates extracted strings through every
    | configured locale.
    |
    | Even if you use Polyglot as Translator of your application, always
    | produce dot.key strings using 'array' driver to respect legacy
    | of Laravel Translator.
    |
    | 'array'       - stores extracted 'dot.key' strings into .php files
    | 'json'        - stores extracted 'natural' strings into .json file
    | 'gettext'     - stores extracted 'natural' strings into .po files
    |
    */

    'producer' => [
        'strings' => 'json',
        'keys' => 'array'
    ],

    /*
    |--------------------------------------------------------------------------
    | Xgettext Extractor Configuration
    |--------------------------------------------------------------------------
    |
    | Gettext groups translations into 'text domains', so we need to configure
    | at least one. For every text domain configure source files and folders
    | to parse and optionally exclude some files and folders from being parsed.
    |
    */

    'xgettext' => [
        [
            'text_domain' => 'messages',
            'category' => LC_MESSAGES,
            'sources' => [
                app_path(),
                resource_path('views')
            ],
            'exclude' => [],
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Polyglot Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Polyglot will be accessible from. If this
    | setting is null, Polyglot will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('POLYGLOT_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Polyglot Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Polyglot will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('POLYGLOT_PATH', 'polyglot'),

    /*
    |--------------------------------------------------------------------------
    | Polyglot Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will be assigned to every Polyglot route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => [
        'web'
    ],

    /*
    |--------------------------------------------------------------------------
    | Gettext Executables Configuration
    |--------------------------------------------------------------------------
    |
    | Paths to gettext binaries.
    |
    */

    'executables' => [
        'xgettext' => env('XGETTEXT_EXECUTABLE', 'xgettext'),
        'msginit' => env('MSGINIT_EXECUTABLE', 'msginit'),
        'msgmerge' => env('MSGMERGE_EXECUTABLE', 'msgmerge'),
        'msgfmt' => env('MSGFMT_EXECUTABLE', 'msgfmt'),
        'msgcat' => env('MSGCAT_EXECUTABLE', 'msgcat')
    ],
];
