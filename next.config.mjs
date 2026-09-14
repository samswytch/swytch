/**
 * The brand packs and the authority envelope are read from disk at request time
 * (see lib/content.ts). Next.js only ships files it can trace statically, and a
 * path built at runtime is not traceable, so the content directory is included
 * explicitly. Without this the assistant deploys and then fails on every request
 * with a missing-file error.
 *
 * @type {import('next').NextConfig}
 */
const nextConfig = {
  // `next dev` otherwise appends a block of its own to CLAUDE.md. That file is
  // the project's instructions, written by hand — a build tool should not be
  // editing it.
  agentRules: false,

  outputFileTracingIncludes: {
    '/api/assistant': ['./content/**/*'],
    '/api/health': ['./content/**/*'],
  },
};

export default nextConfig;
