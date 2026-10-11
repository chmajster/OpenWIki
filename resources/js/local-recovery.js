const RETENTION = 30 * 86400000;

export class LocalRecovery {
    constructor() {
        this.database = new Promise((resolve, reject) => {
            try {
                const request = indexedDB.open('openwiki-editor', 1);
                request.onupgradeneeded = () => {
                    request.result.createObjectStore('copies', {keyPath: 'key'});
                    request.result.createObjectStore('uploads', {keyPath: 'key'});
                };
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
                request.onblocked = () => reject(new Error('Local recovery database is blocked.'));
            } catch (error) { reject(error); }
        });
        // Storage failure is reported by operations, never as an unhandled rejection.
        this.database.catch(() => {});
        this.serial = Promise.resolve();
    }

    async operation(store, mode, callback) {
        const database = await this.database;
        return new Promise((resolve, reject) => {
            const transaction = database.transaction(store, mode);
            const request = callback(transaction.objectStore(store));
            transaction.oncomplete = () => resolve(request.result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    }

    save(key, data) {
        // Later snapshots cannot be overwritten by an earlier asynchronous transaction.
        const task = this.serial.then(async () => {
            const encoded = JSON.stringify(data);
            let fallback = false;
            // Large snapshots stay in the asynchronous store; small copies also have a fallback.
            const mirror = () => {
                if (!localStorage.getItem(key) && Object.keys(localStorage).filter(value => value.startsWith('openwiki:draft:v2:')).length >= 100) return false;
                localStorage.setItem(key, encoded); return true;
            };
            if (encoded.length < 250000) try { fallback = mirror(); } catch {}
            try {
                const records = await this.operation('copies', 'readonly', store => store.getAllKeys());
                if (records.length >= 100 && !records.includes(key)) throw new Error('Local copy quota reached.');
                await this.operation('copies', 'readwrite', store => store.put({key, data, updated: Date.now()}));
                if (encoded.length >= 250000) try { localStorage.removeItem(key); } catch {}
                return true;
            } catch {
                if (!fallback) try { fallback = mirror(); } catch {}
                return fallback;
            }
        });
        this.serial = task.catch(() => {});
        return task;
    }

    async list(prefix) {
        const found = new Map();
        try {
            const records = await this.operation('copies', 'readonly', (store) => store.getAll());
            for (const record of records) {
                if (record.updated < Date.now() - RETENTION) { await this.remove(record.key); continue; }
                if (record.key.startsWith(prefix)) found.set(record.key, record.data);
            }
        } catch {}
        try {
            for (const key of Object.keys(localStorage).filter((key) => key.startsWith(prefix))) {
                try {
                    const data = JSON.parse(localStorage.getItem(key));
                    if (Date.parse(data.savedAt) < Date.now() - RETENTION) { localStorage.removeItem(key); continue; }
                    if (!found.has(key) || found.get(key).sequence < data.sequence) found.set(key, data);
                } catch {}
            }
        } catch {}
        return [...found].map(([localKey, data]) => ({...data, localKey}));
    }

    async remove(key, sequence = Infinity) {
        await this.serial;
        try {
            const record = await this.operation('copies', 'readonly', (store) => store.get(key));
            if (record && record.data.sequence <= sequence) await this.operation('copies', 'readwrite', (store) => store.delete(key));
        } catch {}
        try {
            const data = JSON.parse(localStorage.getItem(key) || 'null');
            if (data && data.sequence <= sequence) localStorage.removeItem(key);
        } catch {}
    }

    async uploads(prefix) {
        try {
            const records = await this.operation('uploads', 'readonly', store => store.getAll());
            for (const record of records) if (record.updated < Date.now() - RETENTION) await this.removeUpload(record.key);
            return records.filter(record => record.updated >= Date.now() - RETENTION && record.key.startsWith(prefix));
        }
        catch { return []; }
    }

    async putUpload(key, data) {
        const records = await this.operation('uploads', 'readonly', store => store.getAll());
        const other = records.filter(record => record.key !== key);
        if (other.length >= 50 || other.reduce((size, record) => size + record.file.size, data.file.size) > 100 * 1024 * 1024) throw new Error('Local upload quota reached.');
        return this.operation('uploads', 'readwrite', store => store.put({key, ...data, updated: Date.now()}));
    }
    removeUpload(key) { return this.operation('uploads', 'readwrite', (store) => store.delete(key)); }
}
