import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';

import { Header } from '@/components/Header';
import './globals.css';

export const metadata: Metadata = {
  title: 'Marketing cover',
  description: 'Brand assistant and log for the cover period, 7 to 23 October.',
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en-GB">
      <body>
        <Header />
        {children}
      </body>
    </html>
  );
}
