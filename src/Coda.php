<?php

declare(strict_types=1);

namespace Claylo\Wp;

/**
 * Handle post-request processing in a variety of environments.
 *
 * @package Claylo
 */
class Coda
{
    static function new(callable $callback, array $params = []): void
    {
        register_shutdown_function(function () use ($callback, $params) {
            error_log('Coda [begin callback]');
            // ensure session data is written and closed before executing the callback
            if (session_id()) {
                session_write_close();
            }

            // ignore user aborts and allow the script to run until completion
            ignore_user_abort(true);

            // disable the time limit for the script
            set_time_limit(0);

            // flush all output buffers
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            // check to see if we should call fastcgi_finish_request
            if (function_exists('fastcgi_finish_request') && strpos(php_sapi_name(), 'cgi') !== false) {
                fastcgi_finish_request();
            }

            // call the callback
            try {
                call_user_func_array($callback, $params);
            } catch (\Throwable $e) {
                error_log('Coda [callback error]: ' . $e->getMessage());
            }

            error_log('Coda [end callback]');
        });
    }
}
