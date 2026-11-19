/*
 * 19 Nov: "Read the documents" without an outside service. The CRM sends the uploaded files to this
 * browser; Tesseract (open source, runs on this computer) reads the text, and the text goes back to the
 * form, where the CRM finds the details. The libraries load only the first time the button is used.
 */
(() => {
    if (window.rpOcrLoaded) return;
    window.rpOcrLoaded = true;

    const TESSERACT = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
    const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    const load = (src) => new Promise((ok, fail) => {
        if (document.querySelector(`script[src="${src}"]`)) return ok();
        const s = document.createElement('script');
        s.src = src;
        s.onload = ok;
        s.onerror = () => fail(new Error('could not load the reader (check the internet connection)'));
        document.head.appendChild(s);
    });

    let box;
    const say = (msg, hideLater) => {
        if (!box) {
            box = document.createElement('div');
            box.className = 'rp-ocr-box';
            box.setAttribute('role', 'status');
            document.body.appendChild(box);
        }
        box.textContent = msg;
        box.style.display = 'block';
        if (hideLater) setTimeout(() => { box.style.display = 'none'; }, 4000);
    };

    const pdfPages = async (dataUrl) => {
        await load(PDFJS);
        // The worker script is loaded on this page too (window.pdfjsWorker), so pdf.js does not need a
        // cross-site worker, which browsers often refuse.
        await load(PDFJS_WORKER);
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
        const bytes = Uint8Array.from(atob(dataUrl.split(',')[1]), (c) => c.charCodeAt(0));
        // disableFontFace: pdf.js draws the letters itself (some PDF fonts are refused by the browser).
        const pdf = await window.pdfjsLib.getDocument({ data: bytes, disableFontFace: true, isEvalSupported: false }).promise;
        const pages = [];
        for (let i = 1; i <= Math.min(pdf.numPages, 3); i++) {
            const page = await pdf.getPage(i);
            // A PDF made on a computer carries its text: use it as is (exact, no reading needed).
            const content = await page.getTextContent();
            let text = '';
            let lastY = null;
            for (const item of content.items) {
                const y = item.transform ? Math.round(item.transform[5]) : null;
                if (lastY !== null && y !== null && Math.abs(y - lastY) > 2) text += '\n';
                else if (text && !text.endsWith(' ') && !text.endsWith('\n')) text += ' ';
                text += item.str || '';
                if (item.hasEOL) text += '\n';
                lastY = y;
            }
            if (text.replace(/\s/g, '').length >= 20) {
                pages.push({ text });
                continue;
            }
            // A scan: draw the page and read it.
            const viewport = page.getViewport({ scale: 2 });
            const canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; // white paper (an empty canvas is transparent, which reads as black)
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            await page.render({ canvasContext: ctx, viewport }).promise;
            pages.push({ image: canvas });
        }
        return pages;
    };

    let running = false;
    window.addEventListener('rp-ocr-run', async (event) => {
        const d = event.detail || {};
        if (running || !d.id || !d.target) return;
        running = true;
        const results = [];
        try {
            say('Loading the document reader…');
            await load(TESSERACT);
            const worker = await window.Tesseract.createWorker('eng');
            const files = d.files || [];
            for (let i = 0; i < files.length; i++) {
                say(`Reading document ${i + 1} of ${files.length}…`);
                const parts = files[i].mime === 'application/pdf' ? await pdfPages(files[i].data) : [{ image: files[i].data }];
                for (const part of parts) {
                    if (part.text !== undefined) {
                        results.push({ type: files[i].type || '', text: part.text });
                        continue;
                    }
                    const { data } = await worker.recognize(part.image);
                    results.push({ type: files[i].type || '', text: (data && data.text) || '' });
                }
            }
            await worker.terminate();
            say('Done. Check the fields before saving.', true);
        } catch (err) {
            console.error(err);
            say('Could not read the documents: ' + (err && err.message ? err.message : err), true);
        } finally {
            running = false;
        }
        const component = window.Livewire && window.Livewire.find(d.id);
        if (component) component.set(d.target, JSON.stringify(results));
    });
})();
