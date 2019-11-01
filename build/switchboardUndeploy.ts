import { env } from './loadEnv';
import { execSync, spawn } from 'child_process';

const stage = (process.env.DEV_SUFFIX) ? process.env.DEV_SUFFIX : 'dev';
const command = `sls remove --stage=${stage}`;
const cwd = `${process.cwd()}/switchboard`;
console.info(`Running ${command} in ${cwd}`);

try {
  execSync(command, { cwd, stdio: 'inherit' });
} catch (error) {
  console.error(error);
  console.info(env);
}