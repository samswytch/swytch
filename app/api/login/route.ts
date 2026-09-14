import { cookieOptions, createToken, passwordMatches } from '@/lib/auth';

/**
 * Plain form post, so the one screen she has to get through works even if
 * something has gone wrong with JavaScript.
 */

/**
 * Relative Location headers, deliberately. A route handler's `request.url` does
 * not reliably carry the host the browser actually used — behind Vercel's proxy,
 * or in dev, it can come back as localhost — and redirecting to the wrong origin
 * silently drops the cookie that was just set.
 */
function redirect(path: string): Response {
  return new Response(null, { status: 303, headers: { Location: path } });
}

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

export async function POST(request: Request) {
  const configured = process.env.APP_PASSWORD;

  if (!configured || configured.trim() === '') {
    return new Response(
      'APP_PASSWORD is not set on this deployment, so nobody can be let in. Set it in the Vercel ' +
        'project settings and redeploy.',
      { status: 500, headers: { 'Content-Type': 'text/plain; charset=utf-8' } },
    );
  }

  const form = await request.formData();
  const submitted = form.get('password');

  if (typeof submitted !== 'string' || !(await passwordMatches(submitted, configured))) {
    return redirect('/login?wrong=1');
  }

  const response = redirect('/');
  const { name, ...options } = cookieOptions;
  response.headers.append(
    'Set-Cookie',
    [
      `${name}=${await createToken(configured)}`,
      `Path=${options.path}`,
      `Max-Age=${options.maxAge}`,
      `SameSite=Lax`,
      'HttpOnly',
      options.secure ? 'Secure' : '',
    ]
      .filter(Boolean)
      .join('; '),
  );
  return response;
}
