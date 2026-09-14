import { COOKIE_NAME } from '@/lib/auth';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

export async function POST() {
  // Relative, for the same reason as app/api/login/route.ts.
  const response = new Response(null, { status: 303, headers: { Location: '/login' } });
  response.headers.append(
    'Set-Cookie',
    `${COOKIE_NAME}=; Path=/; Max-Age=0; SameSite=Lax; HttpOnly${
      process.env.NODE_ENV === 'production' ? '; Secure' : ''
    }`,
  );
  return response;
}
