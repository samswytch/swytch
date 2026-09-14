import { NextResponse, type NextRequest } from 'next/server';

import { COOKIE_NAME, verifyToken } from '@/lib/auth';

/**
 * Everything behind one shared password. The matcher below lets through the
 * login screen, the login route and Next's own static assets; every other path,
 * page or API, needs a valid session cookie.
 *
 * This is Next 16's `proxy` convention — the same thing earlier versions called
 * middleware.
 */
export async function proxy(request: NextRequest) {
  const password = process.env.APP_PASSWORD;

  if (!password || password.trim() === '') {
    // Failing closed and saying why. An app that lets everyone in because a
    // variable is missing is worse than one that is plainly broken.
    return new NextResponse(
      'APP_PASSWORD is not set on this deployment, so nobody can be let in. Set it in the Vercel ' +
        'project settings and redeploy.',
      { status: 500, headers: { 'Content-Type': 'text/plain; charset=utf-8' } },
    );
  }

  if (await verifyToken(request.cookies.get(COOKIE_NAME)?.value, password)) {
    return NextResponse.next();
  }

  if (request.nextUrl.pathname.startsWith('/api/')) {
    return NextResponse.json(
      { error: 'Your session has expired. Reload the page and enter the password again.' },
      { status: 401 },
    );
  }

  const login = request.nextUrl.clone();
  login.pathname = '/login';
  login.search = '';
  return NextResponse.redirect(login);
}

/**
 * Everything except the login screen, the login route and Next's own internals.
 *
 * `_next/` has to be excluded wholesale, not just `_next/static`: the dev client
 * also talks to `_next/hmr`, and redirecting that to the login page stalls
 * hydration, which leaves every button on the page inert. Nothing private is
 * served from under `_next/` — a page's data is fetched through the page's own
 * URL, which stays behind the password.
 *
 * `icon.svg` is excluded for the same reason as favicon.ico: the login screen
 * has to be able to load it before anyone has signed in.
 */
export const config = {
  matcher: ['/((?!login|api/login|_next/|favicon.ico|icon.svg).*)'],
};
