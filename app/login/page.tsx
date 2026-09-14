export const dynamic = 'force-dynamic';

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ wrong?: string }>;
}) {
  const { wrong } = await searchParams;

  return (
    <main className="login">
      <h1 className="page-title">Marketing cover</h1>
      <p className="lede">Sadie and Sam. One password between you.</p>

      <form action="/api/login" method="post">
        <div className="login-field">
          <label className="login-label" htmlFor="password">
            Password
          </label>
          <input
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
            autoFocus
            required
          />
        </div>
        {wrong ? (
          <p className="notice">That password is not right. Try again, or ask Sam or Kev for it.</p>
        ) : null}
        <button type="submit" className="button button-primary login-button">
          Sign in
        </button>
      </form>
    </main>
  );
}
