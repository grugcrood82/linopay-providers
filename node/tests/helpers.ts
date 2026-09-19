import { generateKeyPair, exportPKCS8 } from 'jose';

/**
 * Shared test helper: returns an RS256 PKCS#8 PEM for testing. The
 * matching public half is the one inside the same closure — by design
 * we never mix key pairs across tests.
 */
export async function generateTestPem(): Promise<string> {
  const { privateKey } = await generateKeyPair('RS256', { extractable: true });
  return await exportPKCS8(privateKey);
}
