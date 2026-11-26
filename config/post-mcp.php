<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Post model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model the MCP tools manage. Nothing outside this model and
    | the relations listed under "relations" can be read or written.
    |
    */

    'model' => env('POST_MCP_MODEL', 'App\\Models\\Post'),

    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    |
    | The name and instructions Claude sees when it connects.
    |
    */

    'server' => [
        'name' => env('POST_MCP_SERVER_NAME'),
        'instructions' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | The connector URL is this path on your APP_URL. "middleware" is added
    | after the authentication middleware. "rate_limit" is the number of
    | requests per minute for each user; null turns rate limiting off.
    |
    */

    'route' => [
        'enabled' => true,
        'path' => env('POST_MCP_PATH', 'laravel-post-mcp'),
        'middleware' => [],
        'rate_limit' => env('POST_MCP_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings page
    |--------------------------------------------------------------------------
    |
    | A page at APP_URL + "path" where people allowed by the gate choose, by
    | clicking, which fields Claude sees and changes. It works inside the
    | limits this file sets: it can switch things off, never open what this
    | file keeps closed. Choices are kept in "table" and apply at once.
    |
    */

    'ui' => [
        'enabled' => true,
        'path' => env('POST_MCP_UI_PATH', 'laravel-post-mcp/settings'),
        'middleware' => ['web', 'auth'],
        'table' => 'post_mcp_settings',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication and authorization
    |--------------------------------------------------------------------------
    |
    | mode:
    |   "oauth" - OAuth 2.1 through Laravel Passport. The app signs in on your
    |             site and you approve it. Used by Claude, ChatGPT, Cursor,
    |             VS Code and other apps that support MCP connectors.
    |   "token" - a fixed access key on "guard" (a Passport personal access
    |             token, or a Sanctum token when guard is "sanctum"), for apps
    |             that cannot sign in. Make one with post-mcp:token.
    |
    | gate: the ability checked on every tool call. Until you define it (for
    | example with Gate::define('use-post-mcp', ...)), everyone is denied.
    |
    | login_url: Passport sends signed-out users to the route named "login".
    | When your site has no such route (for example a Filament-only login),
    | set this and the package adds a "login" route that redirects here.
    |
    | register_authorization_view: let the package tell Passport to show its
    | own consent screen (or your resources/views/mcp/authorize.blade.php if
    | you have one). Turn off if you set Passport's view yourself.
    |
    | app_schemes: desktop apps return from sign-in to their own kind of
    | address (cursor://..., vscode://...). These are allowed on top of
    | https and localhost.
    |
    */

    'auth' => [
        'mode' => env('POST_MCP_AUTH', 'oauth'),
        'guard' => env('POST_MCP_GUARD', 'api'),
        'gate' => 'use-post-mcp',
        'oauth_registration' => true,
        'oauth_rate_limit' => 10,
        'login_url' => env('POST_MCP_LOGIN_URL'),
        'register_authorization_view' => true,
        'app_schemes' => ['claude', 'cursor', 'vscode', 'vscode-insiders', 'windsurf'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    |
    | discovery: "auto" builds the field map from the table columns, the
    | model's $fillable/$guarded/$hidden/$casts and your FormRequest rules.
    | "explicit" exposes only the columns listed in "only".
    |
    | Ids, timestamps, guarded, hidden and password-like columns are never
    | writable. Overrides are applied last. Each override may set: label,
    | description, type, required, enum, rules, hidden, read_only.
    |
    | Run `php artisan post-mcp:fields` to see the result.
    |
    */

    'fields' => [
        'discovery' => 'auto',
        'only' => [],
        'except' => [],
        'overrides' => [
            // 'excerpt' => ['description' => 'One or two sentences shown in the post list.'],
        ],
        'cache_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Columns with a special meaning
    |--------------------------------------------------------------------------
    */

    'title_column' => 'title',

    // The column that records who wrote the post, filled with the signed-in
    // user's id on create. Null when the model has no author.
    'author_column' => null,

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    |
    | mode:
    |   "boolean" - one true/false column, e.g. is_published. A published post
    |               with a future publish date counts as scheduled.
    |   "column"  - a string or backed-enum column. Use the backing values.
    |               Set "scheduled" to null if you have no scheduled value:
    |               published + a future date is used instead.
    |
    | published_at: the publish date column, or null if there is none (then
    | scheduling is not offered).
    |
    */

    'status' => [
        'mode' => 'column',
        'column' => 'status',
        'values' => [
            'draft' => 'draft',
            'published' => 'published',
            'scheduled' => 'scheduled',
        ],
        'published_at' => 'published_at',
        'clear_date_on_unpublish' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug
    |--------------------------------------------------------------------------
    |
    | strategy:
    |   "from_title" - made from the title on create when none is given, kept
    |                  unique. Never changed on update unless Claude sends one.
    |   "model"      - your model fills it (e.g. a saving hook or a package).
    |   "none"       - treated as an ordinary field.
    |
    | reserved: slugs that would clash with your routes.
    |
    */

    'slug' => [
        'column' => 'slug',
        'strategy' => 'from_title',
        'source' => 'title',
        'reserved' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    |
    | format: how the body is stored: "html", "markdown" or "blocks" (JSON).
    | input_format: what Claude writes; defaults to "format".
    | converter: a class implementing Smith89k\PostMcp\Contracts\ContentConverter
    | that turns the input format into the stored one, for example
    | Smith89k\PostMcp\Content\MarkdownToHtmlConverter.
    |
    */

    'content' => [
        'column' => 'body',
        'format' => 'html',
        'input_format' => null,
        'converter' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Your own FormRequest classes. Their rules() are merged over the rules the
    | package works out, so Claude is held to the same rules as your admin.
    |
    */

    'validation' => [
        'create' => null,
        'update' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    |
    | Only the relationships listed here can be written. Claude refers to
    | related records by "label" (e.g. a tag's name) or by id.
    |
    | 'tags' => [
    |     'label' => 'name',          // column used to look records up
    |     'creatable' => true,        // create missing ones by label
    |     'rules' => ['max:50'],      // rules for a new label
    |     'description' => 'Topics.', // shown to Claude
    |     'details' => [              // other columns Claude fills in on new items
    |         'summary' => ['rules' => ['max:200'], 'description' => 'One line.'],
    |         'links' => ['each' => ['url' => ['required', 'url'], 'label' => ['nullable', 'max:40']]],
    |         'logo' => ['image' => ['disk' => 'public', 'directory' => 'tags']],
    |     ],
    | ],
    |
    | With "details", the AI app can send an item as an object (label plus
    | details) to finish it while writing the post. New items get every detail
    | sent; existing ones get their empty details filled in and new entries
    | added to lists (such as links), never changed or removed. Add
    | 'grow' => false to a list detail to keep its entries on existing items
    | as they are. Lists nobody described get their entry shape from the
    | items already saved. Details only
    | work when "creatable" is on. Images are downloaded from a public URL.
    |
    */

    'relations' => [],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | driver: "none", "disk", "medialibrary" or a class implementing
    | Smith89k\PostMcp\Contracts\MediaDriver.
    |
    | attach_to: a column (disk driver) or collection (medialibrary) that holds
    | the featured image, used when upload_image gets a post id. Null returns
    | a URL and a snippet for Claude to place in the body. attach_value says
    | whether the column gets the stored "path" or the full "url".
    |
    */

    'media' => [
        'driver' => 'none',
        'disk' => 'public',
        'directory' => 'posts',
        'collection' => 'images',
        'attach_to' => null,
        'attach_value' => 'path',
        'max_kb' => 5120,
        'mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'timeout' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Writer
    |--------------------------------------------------------------------------
    |
    | The class that saves posts. The default fills and saves the model, so
    | your model events and observers run. Bind your own class implementing
    | Smith89k\PostMcp\Contracts\PostWriter to save through your services.
    |
    */

    'writer' => null,

    /*
    |--------------------------------------------------------------------------
    | Public URL
    |--------------------------------------------------------------------------
    |
    | A named route that takes the post, used to give Claude a link.
    |
    */

    'public_url' => [
        'route' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    'list' => [
        'per_page' => 15,
        'max_per_page' => 50,
        'search' => ['title'],
        'order_by' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deleting
    |--------------------------------------------------------------------------
    |
    | Off by default. When on, a delete_post tool is offered.
    |
    */

    'delete' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Every create, update, publish, unpublish, schedule, upload and delete is
    | written to this table, and to a log channel when one is set.
    |
    */

    'audit' => [
        'enabled' => true,
        'table' => 'post_mcp_audit_logs',
        'log_channel' => env('POST_MCP_LOG_CHANNEL'),
    ],

];
