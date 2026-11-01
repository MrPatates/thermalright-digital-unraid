FROM docker.io/library/python@sha256:2986c55feb36e6cae00fa1fefb454283e4b33f35e75ff8bdd123b134130be301
ARG TD_VERSION=2026.11.01.1
LABEL io.local.thermalright-digital.owner="thermalright-digital" \
      org.opencontainers.image.version="$TD_VERSION" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.description="TRCC 9.11.2 transport with Unraid host metrics"
# Bookworm's USB library has no transitive packages beyond libc in this base.
RUN apt-get update -qq && apt-get install -y --no-install-recommends libusb-1.0-0=2:1.0.26-1 \
    && rm -rf /var/lib/apt/lists/*
COPY requirements.txt trcc_linux-9.11.2-py3-none-any.whl /build/
RUN echo 'e5a84a9b656763d9b5fc330e4b0ab1e0b6ae4b7382958edf0b14c99176a97dc7  /build/trcc_linux-9.11.2-py3-none-any.whl' | sha256sum -c - \
    && python -m pip install --no-cache-dir --require-hashes -r /build/requirements.txt \
    && python -m pip install --no-cache-dir --no-deps /build/trcc_linux-9.11.2-py3-none-any.whl \
    && rm -rf /build
COPY worker.py /opt/thermalright/worker.py
ENV TRCC_DAEMON=0 QT_QPA_PLATFORM=offscreen PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 HOME=/tmp
RUN python -c 'from trcc.adapters.device.led import Led; from trcc.adapters.device.transport import PyUsbBulkTransport; from trcc.services.led_segment import get_display'
ENTRYPOINT ["python", "/opt/thermalright/worker.py"]
