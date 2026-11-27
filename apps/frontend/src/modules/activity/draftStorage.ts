const DB_NAME = 'platform_activity_drafts_v1';
const STORE_NAME = 'drafts';

interface DraftRecord {
  key: string;
  organizationId: string;
  personId: string;
  attemptId: string;
  revision: number;
  answers: Record<string, unknown>;
  updatedAt: string;
}

function makeKey(organizationId: string, personId: string, attemptId: string): string {
  return `${organizationId}:${personId}:${attemptId}`;
}

function openDb(): Promise<IDBDatabase | null> {
  if (typeof indexedDB === 'undefined') {
    return Promise.resolve(null);
  }
  return new Promise((resolve) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'key' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => resolve(null);
  });
}

export async function saveLocalDraft(
  organizationId: string,
  personId: string,
  attemptId: string,
  revision: number,
  answers: Record<string, unknown>,
): Promise<void> {
  const plainAnswers = JSON.parse(JSON.stringify(answers ?? {})) as Record<string, unknown>;
  const rec: DraftRecord = {
    key: makeKey(organizationId, personId, attemptId),
    organizationId,
    personId,
    attemptId,
    revision,
    answers: plainAnswers,
    updatedAt: new Date().toISOString(),
  };
  const db = await openDb();
  if (!db) {
    try {
      sessionStorage.setItem(rec.key, JSON.stringify(rec));
    } catch {
      // Ignore quota errors
    }
    return;
  }
  await new Promise<void>((resolve) => {
    const tx = db.transaction(STORE_NAME, 'readwrite');
    tx.objectStore(STORE_NAME).put(rec);
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
  });
}

export async function loadLocalDraft(
  organizationId: string,
  personId: string,
  attemptId: string,
): Promise<DraftRecord | null> {
  const key = makeKey(organizationId, personId, attemptId);
  const db = await openDb();
  if (!db) {
    try {
      const raw = sessionStorage.getItem(key);
      return raw ? (JSON.parse(raw) as DraftRecord) : null;
    } catch {
      return null;
    }
  }
  return new Promise((resolve) => {
    const tx = db.transaction(STORE_NAME, 'readonly');
    const req = tx.objectStore(STORE_NAME).get(key);
    req.onsuccess = () => resolve((req.result as DraftRecord | undefined) ?? null);
    req.onerror = () => resolve(null);
  });
}

export async function removeLocalDraft(
  organizationId: string,
  personId: string,
  attemptId: string,
): Promise<void> {
  const key = makeKey(organizationId, personId, attemptId);
  const db = await openDb();
  if (!db) {
    try {
      sessionStorage.removeItem(key);
    } catch {
      // Ignore storage errors
    }
    return;
  }
  await new Promise<void>((resolve) => {
    const tx = db.transaction(STORE_NAME, 'readwrite');
    tx.objectStore(STORE_NAME).delete(key);
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
  });
}

export async function clearAllActivityDrafts(): Promise<void> {
  const db = await openDb();
  if (db) {
    await new Promise<void>((resolve) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      tx.objectStore(STORE_NAME).clear();
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }
}
