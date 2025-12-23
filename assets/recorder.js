/**
 * Spinnio Audio Recorder
 * Minimal MediaRecorder UI + upload to WP REST endpoint.
 *
 * Server contract:
 * POST restUrl (multipart/form-data)
 * - file: Blob
 * - filename: string
 * - upload_nonce: string
 * - consumer (optional)
 * - reference_id (optional)
 * - requested_storage (optional)
 * - folder (optional)
 *
 * Returns:
 * { ok: true, attachment_id, url, storage } or { ok:false, error }
 */

(function () {
  "use strict";

  const cfg = window.SpinnioAudioRecorder || null;
  if (!cfg) return;

  const wraps = document.querySelectorAll('[data-sar="1"]');
  if (!wraps.length) return;

  function pad2(n) {
    return String(n).padStart(2, "0");
  }

  function fmtTime(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${pad2(m)}:${pad2(s)}`;
  }

  function pickMimeType() {
    const preferred = [
      "audio/webm;codecs=opus",
      "audio/webm",
      "audio/ogg;codecs=opus",
      "audio/ogg",
    ];
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return "";
    for (const t of preferred) {
      if (MediaRecorder.isTypeSupported(t)) return t;
    }
    return "";
  }

  function getContextFromRoot(root) {
    // These come from PHP as data-sar-*
    return {
      consumer: root.getAttribute("data-sar-consumer") || "",
      reference_id: root.getAttribute("data-sar-reference-id") || "",
      requested_storage: root.getAttribute("data-sar-requested-storage") || "",
      folder: root.getAttribute("data-sar-folder") || "",
    };
  }

  async function initRecorderUI(root) {
    const statusEl = root.querySelector("[data-sar-status]");
    const startBtn = root.querySelector("[data-sar-start]");
    const stopBtn = root.querySelector("[data-sar-stop]");
    const uploadBtn = root.querySelector("[data-sar-upload]");
    const resetBtn = root.querySelector("[data-sar-reset]");
    const timerEl = root.querySelector("[data-sar-timer]");
    const audioEl = root.querySelector("[data-sar-audio]");
    const resultEl = root.querySelector("[data-sar-result]");
    const urlEl = root.querySelector("[data-sar-url]");
    const idEl = root.querySelector("[data-sar-id]");

    let stream = null;
    let recorder = null;
    let chunks = [];
    let blob = null;
    let objectUrl = null;

    let seconds = 0;
    let timer = null;

    function setStatus(msg) {
      statusEl.textContent = msg;
    }

    function setTimer(sec) {
      timerEl.textContent = fmtTime(sec);
    }

    function resetAll() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
      seconds = 0;
      setTimer(0);

      chunks = [];
      blob = null;

      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
      }

      audioEl.style.display = "none";
      audioEl.src = "";
      audioEl.load();

      resultEl.style.display = "none";
      urlEl.href = "#";
      urlEl.textContent = "Open audio";
      idEl.textContent = "";

      uploadBtn.disabled = true;
      resetBtn.disabled = true;

      stopBtn.disabled = true;
      startBtn.disabled = false;

      setStatus("Ready.");
    }

    async function ensureMediaSupport() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error("This browser does not support microphone capture. Use Chrome.");
      }
      if (!window.MediaRecorder) {
        throw new Error("This browser does not support MediaRecorder. Use Chrome.");
      }
    }

    async function startRecording() {
      await ensureMediaSupport();

      setStatus("Requesting microphone permission...");
      const mimeType = pickMimeType();

      stream = await navigator.mediaDevices.getUserMedia({ audio: true });

      chunks = [];
      blob = null;

      try {
        recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
      } catch (e) {
        recorder = new MediaRecorder(stream);
      }

      recorder.ondataavailable = (evt) => {
        if (evt.data && evt.data.size > 0) {
          chunks.push(evt.data);
        }
      };

      recorder.onstart = () => {
        setStatus("Recording...");
        startBtn.disabled = true;
        stopBtn.disabled = false;
        uploadBtn.disabled = true;
        resetBtn.disabled = true;

        seconds = 0;
        setTimer(0);
        timer = setInterval(() => {
          seconds += 1;
          setTimer(seconds);

          if (cfg.maxSeconds && seconds >= cfg.maxSeconds) {
            setStatus("Max recording length reached. Stopping...");
            stopRecording();
          }
        }, 1000);
      };

      recorder.onstop = () => {
        if (timer) {
          clearInterval(timer);
          timer = null;
        }

        if (stream) {
          stream.getTracks().forEach((t) => t.stop());
          stream = null;
        }

        const type = (recorder && recorder.mimeType) ? recorder.mimeType : (mimeType || "audio/webm");
        blob = new Blob(chunks, { type });

        if (cfg.maxBytes && blob.size > cfg.maxBytes) {
          setStatus("Recording is too large. Please record a shorter clip.");
          resetBtn.disabled = false;
          uploadBtn.disabled = true;
          stopBtn.disabled = true;
          startBtn.disabled = false;
          return;
        }

        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = URL.createObjectURL(blob);
        audioEl.src = objectUrl;
        audioEl.style.display = "block";
        audioEl.load();

        setStatus("Recording ready. You can play it back and upload.");
        uploadBtn.disabled = false;
        resetBtn.disabled = false;
        stopBtn.disabled = true;
        startBtn.disabled = false;
      };

      recorder.start();
    }

    function stopRecording() {
      try {
        if (recorder && recorder.state !== "inactive") {
          recorder.stop();
        }
      } catch (e) {
        setStatus("Failed to stop recording cleanly.");
      } finally {
        stopBtn.disabled = true;
      }
    }

    function buildFilename() {
      const ts = new Date().toISOString().replace(/[:.]/g, "-");
      const t = blob && blob.type ? blob.type : "";
      let ext = "webm";
      if (t.includes("ogg")) ext = "ogg";
      if (t.includes("webm")) ext = "webm";
      return `voice-recording-${ts}.${ext}`;
    }

    async function uploadRecording() {
      if (!blob) {
        setStatus("Nothing to upload. Record something first.");
        return;
      }

      setStatus("Uploading...");
      uploadBtn.disabled = true;
      startBtn.disabled = true;
      resetBtn.disabled = true;

      const context = getContextFromRoot(root);

      const form = new FormData();
      const filename = buildFilename();

      form.append("file", blob, filename);
      form.append("filename", filename);
      form.append("upload_nonce", cfg.uploadNonce);

      // Optional context (only append if present)
      if (context.consumer) form.append("consumer", context.consumer);
      if (context.reference_id) form.append("reference_id", context.reference_id);
      if (context.requested_storage) form.append("requested_storage", context.requested_storage);
      if (context.folder) form.append("folder", context.folder);

      const resp = await fetch(cfg.restUrl, {
        method: "POST",
        headers: {
          "X-WP-Nonce": cfg.nonce,
        },
        body: form,
        credentials: "same-origin",
      });

      const data = await resp.json().catch(() => null);

      if (!resp.ok || !data || !data.ok) {
        const msg = (data && data.error) ? data.error : `Upload failed (HTTP ${resp.status}).`;
        setStatus(msg);
        uploadBtn.disabled = false;
        startBtn.disabled = false;
        resetBtn.disabled = false;
        return;
      }

      // Success (back-compat: attachment_id + url may be null for external storage)
      setStatus("Saved.");
      resultEl.style.display = "block";

      if (data.url) {
        urlEl.href = data.url;
        urlEl.textContent = data.url;
      } else {
        urlEl.href = "#";
        urlEl.textContent = "No URL returned";
      }

      idEl.textContent = data.attachment_id ? String(data.attachment_id) : "—";

      startBtn.disabled = false;
      resetBtn.disabled = false;
      uploadBtn.disabled = true;
    }

    startBtn.addEventListener("click", () => {
      resetAll();
      startRecording().catch((err) => {
        setStatus(err && err.message ? err.message : "Failed to start recording.");
        startBtn.disabled = false;
        stopBtn.disabled = true;
      });
    });

    stopBtn.addEventListener("click", () => stopRecording());

    uploadBtn.addEventListener("click", () => uploadRecording().catch((err) => {
      setStatus(err && err.message ? err.message : "Upload error.");
      uploadBtn.disabled = false;
      startBtn.disabled = false;
      resetBtn.disabled = false;
    }));

    resetBtn.addEventListener("click", () => resetAll());

    resetAll();
  }

  wraps.forEach((w) => initRecorderUI(w));
})();