#!/usr/bin/env python3
"""
transcribe_worker.py — Transcrição com faster-whisper (chamado pelo cron PHP).

Recebe um arquivo de áudio (WAV 16 kHz mono, já extraído pelo cron via ffmpeg),
transcreve e grava em disco um JSON único (segmentos + palavras + idioma).
O PHP (scripts/transcribe_cron.php) orquestra: baixa a fonte do GCS, extrai o
áudio, chama este worker em background, acompanha o progresso via
`--progress` e, ao terminar, gera SRT/VTT/TXT e persiste na tabela `subtitles`.

Saída (--out-json):
    {
      "language": "pt",
      "language_probability": 0.9994,
      "duration": 123.45,
      "segments": [ {"start": 0.5, "end": 2.0, "text": "...", "speaker": "Palestrante 1"}, ... ],
      "words":     [ {"word": "...", "start": 0.5, "end": 0.9, "speaker": "Palestrante 1"}, ... ],
      "speakers":  ["Palestrante 1"]
    }

Progresso (--progress): arquivo JSON reescrito atomicamente a cada segmento,
    {"state": "loading"|"processing"|"done"|"error", "segments_done": n,
     "progress": 0..1, "error": "..." }

Sem diarização real: o falante é FIXO em "Palestrante 1" (decisão de projeto).
Idioma: `--lang auto` (ou vazio) deixa o modelo detectar (language=None).

Instalação do venv (VM Debian; rodar 1x via SSH):
    python3 -m venv /etc/avscms/whisper-venv
    sudo /etc/avscms/whisper-venv/bin/pip install -U faster-whisper

O cache do modelo fica fora do checkout (HF_HOME/XDG_CACHE_HOME apontados pelo
cron para /etc/avscms/whisper-models), então o rsync --delete do deploy nunca
o destrói.
"""

import argparse
import json
import os
import sys

SPEAKER = "Palestrante 1"


def write_progress(path, state, segments_done=0, progress=0.0, error=""):
    """Grava o arquivo de progresso atomicamente (tmp + rename)."""
    payload = {
        "state": state,
        "segments_done": int(segments_done),
        "progress": round(min(1.0, max(0.0, float(progress))), 4),
        "error": error[:1000],
    }
    try:
        tmp = path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(payload, fh)
        os.replace(tmp, path)
    except Exception:
        pass


def main():
    parser = argparse.ArgumentParser(description="Faster-whisper transcribe worker")
    parser.add_argument("--audio", required=True, help="WAV 16 kHz mono de entrada")
    parser.add_argument("--model", default="small", help="tamanho do modelo (base/small/medium)")
    parser.add_argument("--lang", default="auto", help="idioma (auto = autodetecta)")
    parser.add_argument("--duration", type=float, default=0.0, help="duração do áudio em segundos (p/ progresso)")
    parser.add_argument("--out-json", required=True, help="caminho do JSON de saída")
    parser.add_argument("--progress", required=True, help="caminho do arquivo de progresso")
    parser.add_argument("--device", default="cpu", help="cpu/cuda")
    parser.add_argument("--compute-type", default="int8", help="int8/float16/float32")
    args = parser.parse_args()

    if not os.path.isfile(args.audio):
        sys.exit("Arquivo de áudio não encontrado: %s" % args.audio)

    write_progress(args.progress, "loading")

    try:
        from faster_whisper import WhisperModel
    except ImportError:
        sys.exit("faster-whisper não está instalado no venv (ver header deste script)")

    try:
        model = WhisperModel(
            args.model,
            device=args.device,
            compute_type=args.compute_type,
        )
    except Exception as exc:
        write_progress(args.progress, "error", error="falha ao carregar o modelo: %s" % exc)
        sys.exit("falha ao carregar o modelo: %s" % exc)

    language = None if args.lang in ("", "auto") else args.lang

    try:
        segments, info = model.transcribe(
            args.audio,
            language=language,
            word_timestamps=True,
            vad_filter=True,
            beam_size=5,
        )
    except Exception as exc:
        write_progress(args.progress, "error", error="falha ao transcrever: %s" % exc)
        sys.exit("falha ao transcrever: %s" % exc)

    out_segments = []
    out_words = []
    segments_done = 0
    total_seconds = float(args.duration or 0.0)

    # O iterador de segments é lazy: o progresso acompanha o fim do último
    # segmento processado sobre a duração total (quando conhecida).
    try:
        for seg in segments:
            start = round(float(getattr(seg, "start", 0.0)), 3)
            end = round(float(getattr(seg, "end", 0.0)), 3)
            text = (getattr(seg, "text", "") or "").strip()
            out_segments.append({
                "start": start,
                "end": end,
                "text": text,
                "speaker": SPEAKER,
            })
            for word in (getattr(seg, "words", None) or []):
                out_words.append({
                    "word": (getattr(word, "word", "") or "").strip(),
                    "start": round(float(getattr(word, "start", 0.0)), 3),
                    "end": round(float(getattr(word, "end", 0.0)), 3),
                    "speaker": SPEAKER,
                })
            segments_done += 1
            if total_seconds > 0 and end > 0:
                progress = end / total_seconds
            else:
                progress = 0.0
            write_progress(args.progress, "processing", segments_done, progress)
    except Exception as exc:
        write_progress(args.progress, "error", error="erro durante a transcrição: %s" % exc)
        sys.exit("erro durante a transcrição: %s" % exc)

    detected = getattr(info, "language", None) or args.lang or "auto"
    probability = getattr(info, "language_probability", 0.0) or 0.0
    duration = getattr(info, "duration", None) or total_seconds

    data = {
        "language": str(detected),
        "language_probability": round(float(probability), 4),
        "duration": round(float(duration), 3),
        "segments": out_segments,
        "words": out_words,
        "speakers": [SPEAKER] if out_segments else [],
    }

    tmp = args.out_json + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False)
    os.replace(tmp, args.out_json)

    write_progress(args.progress, "done", segments_done, 1.0)


if __name__ == "__main__":
    main()