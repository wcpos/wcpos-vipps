// @vitest-environment node
import { execSync } from 'child_process';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('production build', () => {
  const distPath = resolve(__dirname, '../../dist/payment.js');
  let bundleContent: string;

  beforeAll(() => {
    execSync('npm run build', {
      cwd: resolve(__dirname, '../../..'),
      stdio: 'pipe',
    });
    bundleContent = readFileSync(distPath, 'utf-8');
  });

  it('should not contain process.env references', () => {
    // Vite library mode must replace process.env.NODE_ENV at build time.
    // Bare process.env in the bundle causes "process is not defined" at runtime.
    expect(bundleContent).not.toMatch(/\bprocess\.env\b/);
  });

  it('should not contain bare process references', () => {
    // Also catch patterns like process.env or process["env"] that slip through.
    // Allow "process" inside strings (e.g. error messages) by only matching
    // unquoted references that look like property access.
    const lines = bundleContent.split('\n');
    const stripQuoted = (line: string) =>
      line.replace(/(['"`])(?:\\.|(?!\1).)*\1/g, '');
    const bareProcessEnv = /\bprocess\s*(?:\.\s*env|\[\s*["']?env["']?\s*\])/;
    const offending = lines.filter((line) => bareProcessEnv.test(stripQuoted(line)));
    expect(offending).toHaveLength(0);
  });
});
