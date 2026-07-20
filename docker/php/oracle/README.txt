Place Oracle Instant Client zip files here to enable pdo_oci.

Required files (download from https://www.oracle.com/database/technologies/instant-client/linux-x86-64-downloads.html):
  - instantclient-basic-linux.x64-21.*.zip
  - instantclient-sdk-linux.x64-21.*.zip

Then rebuild with:
  docker compose build --build-arg ORACLE=1 php
