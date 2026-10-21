#!/usr/bin/env node
/**
 * trigger-scheduler.js
 *
 * HTTP endpoint to trigger Laravel scheduler commands via HTTP.
 * This can be called by external schedulers (cron-job.org, GitHub Actions, etc.)
 * on free-tier hosting where Laravel's built-in scheduler doesn't run.
 *
 * Security: Requires a shared secret token passed via Authorization header.
 */

const express = require('express');
const { execSync } = require('child_process');

const app = express();
app.use(express.json());

const SCHEDULER_SECRET = process.env.SCHEDULER_SECRET || 'dev-secret-change-in-production';
const PORT = process.env.PORT || 3001;

const ALLOWED_COMMANDS = [
  'model:prune',
  'recurring-expenses:sync-reminders'
];

function verifyAuth(req, res, next) {
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ error: 'Missing or invalid Authorization header' });
  }
  const token = authHeader.substring(7);
  if (token !== SCHEDULER_SECRET) {
    return res.status(403).json({ error: 'Invalid scheduler token' });
  }
  next();
}

app.post('/scheduler/run', verifyAuth, async (req, res) => {
  const { command } = req.body;
  
  if (!command || !ALLOWED_COMMANDS.includes(command)) {
    return res.status(400).json({ 
      error: `Invalid command. Allowed: ${ALLOWED_COMMANDS.join(', ')}` 
    });
  }

  try {
    const cmd = `php artisan ${command}`;
    console.log(`Executing: ${cmd}`);
    const output = execSync(cmd, { 
      cwd: process.cwd(),
      encoding: 'utf8',
      timeout: 120000,
      maxBuffer: 1024 * 1024
    });
    res.json({ success: true, output: output.trim() });
  } catch (error) {
    console.error(`Command failed: ${error.message}`);
    res.status(500).json({ 
      error: 'Command execution failed', 
      details: error.message,
      stdout: error.stdout?.toString(),
      stderr: error.stderr?.toString()
    });
  }
});

app.get('/scheduler/health', (req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

app.listen(PORT, () => {
  console.log(`Scheduler trigger server running on port ${PORT}`);
  console.log(`Allowed commands: ${ALLOWED_COMMANDS.join(', ')}`);
});

module.exports = app;