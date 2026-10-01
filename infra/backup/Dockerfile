FROM postgres:18

RUN apt-get update && apt-get install -y --no-install-recommends jq \
    && rm -rf /var/lib/apt/lists/*
COPY backup.sh /opt/backup/backup.sh
COPY deployment.sh /opt/backup/deployment.sh
RUN chmod 0755 /opt/backup/backup.sh
ENTRYPOINT ["bash", "/opt/backup/backup.sh"]
CMD ["loop"]
