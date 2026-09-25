<?php

namespace SUBandL\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SUBandL\Support\Assets;

/**
 * Adds the global widget (payment reminder, update modal, backup status) to
 * every full HTML page, so a host application needs no layout changes and it
 * works the same under Blade, Vue or React.
 *
 * Only whole documents are touched: Inertia visits (X-Inertia), JSON,
 * redirects, downloads and streamed responses pass through unchanged. It is
 * also injected for guests, because an Inertia login never reloads the
 * document — the widget itself stays idle until the user is signed in.
 */
class InjectWidget
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! config('subandl.widget.enabled', true)
            || ! $request->isMethod('GET')
            || $request->header('X-Inertia')
            || ! $response instanceof Response
            || ! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return $response;
        }

        $content = (string) $response->getContent();
        $position = strripos($content, '</body>');

        if ($position === false || str_contains($content, 'id="subandl-widget"')) {
            return $response;
        }

        $response->setContent(substr($content, 0, $position) . $this->snippet($request) . substr($content, $position));

        return $response;
    }

    private function snippet(Request $request): string
    {
        $base = rtrim(url((string) config('subandl.routes.prefix', '')), '/');
        $version = Assets::version();

        $config = [
            'base' => $base,
            'auth' => (bool) $request->user(),
            'hiddenOn' => array_values((array) config('subandl.widget.hidden_on', [])),
            'reminderMinutes' => (int) config('subandl.widget.reminder_minutes', 10),
            'updateSnoozeHours' => (int) config('subandl.widget.update_snooze_hours', 6),
            'edgeTab' => (bool) config('subandl.widget.edge_tab', true),
        ];

        return sprintf(
            '<link rel="stylesheet" href="%1$s/subandl/assets/widget.css?v=%2$s">'
            . '<script type="module" id="subandl-widget" src="%1$s/subandl/assets/widget.js?v=%2$s" data-config="%3$s"></script>',
            e($base),
            $version,
            e(json_encode($config, JSON_UNESCAPED_SLASHES)),
        );
    }
}
