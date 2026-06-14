import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { PrismaClient } = require('@prisma/client');

const prisma = new PrismaClient();
prisma.$connect()
  .then(() => { console.log('CONNECTED OK'); return prisma.$disconnect(); })
  .catch(e => { console.log('ERROR:', e.message); process.exit(1); });
