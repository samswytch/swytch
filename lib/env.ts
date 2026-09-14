/**
 * Environment access, in one place, that fails loudly.
 *
 * BRIEF.md §11: "Errors are legible. Every failure mode gets a plain-English
 * message telling her what to do." A missing environment variable is the most
 * likely deployment failure, so it names itself rather than surfacing as
 * `undefined` three layers down.
 */

export class ConfigError extends Error {}

function required(name: string): string {
  const value = process.env[name];
  if (value === undefined || value.trim() === '') {
    throw new ConfigError(
      `${name} is not set on this deployment. Copy .env.example to .env.local for local work, ` +
        `or set it in the Vercel project settings, then redeploy.`,
    );
  }
  return value.trim();
}

function positiveInt(name: string, fallback: number): number {
  const raw = process.env[name];
  if (raw === undefined || raw.trim() === '') return fallback;
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new ConfigError(`${name} must be a whole number greater than zero. It is currently "${raw}".`);
  }
  return parsed;
}

/**
 * Confirmed against https://platform.claude.com/docs/en/about-claude/models/overview
 * on 14 September 2026: claude-opus-5 is the current recommended model. Override
 * with ANTHROPIC_MODEL if that changes before deployment.
 */
const DEFAULT_MODEL = 'claude-opus-5';

const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'] as const;
export type Effort = (typeof EFFORT_LEVELS)[number];

function effort(): Effort {
  const raw = (process.env.ASSISTANT_EFFORT ?? '').trim();
  if (raw === '') return 'medium';
  if (!(EFFORT_LEVELS as readonly string[]).includes(raw)) {
    throw new ConfigError(
      `ASSISTANT_EFFORT must be one of ${EFFORT_LEVELS.join(', ')}. It is currently "${raw}".`,
    );
  }
  return raw as Effort;
}

export const env = {
  get anthropicApiKey(): string {
    return required('ANTHROPIC_API_KEY');
  },
  get anthropicModel(): string {
    return (process.env.ANTHROPIC_MODEL ?? '').trim() || DEFAULT_MODEL;
  },
  /**
   * How hard the model works on each reply. Higher is slower. `medium` keeps a
   * reply comfortably inside a serverless function's time limit while still
   * giving a considered pack check; raise it if the trial week says the checks
   * are too shallow.
   */
  get assistantEffort(): Effort {
    return effort();
  },
  get appPassword(): string {
    return required('APP_PASSWORD');
  },
  get databaseUrl(): string {
    return required('DATABASE_URL');
  },
  get dailyMessageCap(): number {
    return positiveInt('ASSISTANT_DAILY_MESSAGE_CAP', 200);
  },
  get sessionMessageCap(): number {
    return positiveInt('ASSISTANT_SESSION_MESSAGE_CAP', 50);
  },
};
