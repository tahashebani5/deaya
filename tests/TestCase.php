<?php

namespace Tests;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every request in a test signs in from scratch, exactly as every request in production does.
     *
     * **Without this line a permission test can pass for the wrong reason, or fail for one.** The
     * application is a singleton for the length of a test while each `call()` builds a fresh
     * request against it, and `RequestGuard::user()` memoises the user it resolved the first
     * time — Sanctum never re-reads the bearer token afterwards. So the *second* request in a
     * test is authenticated as whoever made the *first*, whatever token it carries and even if it
     * carries none. `OrderReinstatementTest` is where it surfaced: its arrange step walks an order
     * to «إلغاء تام» through the foreman's token, and the poorer token the act step then sends is
     * never looked at — `test_it_needs_a_signed_in_user` sent no token at all and still got 200.
     *
     * That makes it worse than three red tests: every `assertForbidden()` that follows an
     * authenticated request in the same test was asserting nothing, and would have gone on
     * passing if the grant behind it were deleted.
     *
     * `forgetGuards()` rather than `Auth::logout()`: the guard object itself is what holds the
     * memo, so it has to be discarded, not emptied. Placed on `call()` rather than in `setUp()`
     * because the leak is between two requests inside one test, which is precisely where a
     * per-test hook cannot reach.
     *
     * **Safe here because nothing in this suite uses `actingAs()`** — every test signs in with a
     * real personal access token on purpose, as `AuthTest` and `NotificationApiTest` both explain
     * in their own comments. A guard forgotten between requests would discard a user that
     * `actingAs()` had set by hand, so that is the fact to re-check before reaching for it.
     *
     * {@inheritDoc}
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->make(AuthFactory::class)->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
