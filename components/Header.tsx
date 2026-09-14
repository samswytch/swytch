'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

/**
 * The export button sits here rather than on one screen because §10 asks for it
 * on every screen — it is the insurance policy, and it should never be more than
 * one press away.
 */
export function Header() {
  const pathname = usePathname();
  if (pathname === '/login') return null;

  return (
    <header className="header">
      <span className="header-name">Marketing cover</span>
      <nav className="header-nav">
        <Link href="/" aria-current={pathname === '/' ? 'page' : undefined}>
          Assistant
        </Link>
        <Link href="/log" aria-current={pathname === '/log' ? 'page' : undefined}>
          Log
        </Link>
        <a href="/api/log/export" download>
          Export log
        </a>
        <form action="/api/logout" method="post">
          <button type="submit" className="linkbutton">
            Sign out
          </button>
        </form>
      </nav>
    </header>
  );
}
