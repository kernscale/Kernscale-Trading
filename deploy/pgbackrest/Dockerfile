# syntax=docker/dockerfile:1.26@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32
FROM --platform=$BUILDPLATFORM golang:1.27.3-trixie@sha256:604106102d33b1084e37ca08adf98ac0813624ae8595bd0d7f3965ecebded09c AS gosu
ARG TARGETARCH
WORKDIR /src
ADD --checksum=sha256:33d7537d588ea49458b9509bcf4554bdf5ceacc66da71e5caa1058ea3b689c3b https://codeload.github.com/tianon/gosu/tar.gz/6456aaa0f3c854d199d0f037f068eb97515b7513 /tmp/gosu.tar.gz
RUN tar -xzf /tmp/gosu.tar.gz --strip-components=1 -C /src \
    && printf 'package main\n\nconst Version = "1.19-kernscale.1"\n' > version.go
COPY gosu.mod /src/go.mod
COPY gosu.sum /src/go.sum
ENV CGO_ENABLED=0
RUN test "$TARGETARCH" = amd64 \
    && go mod download && go mod verify \
    && GOOS=linux GOARCH=amd64 go build -mod=readonly -trimpath -buildvcs=false -ldflags '-d -w' -o /out/gosu . \
    && mkdir -p /out/licenses \
    && cp LICENSE /out/licenses/gosu.txt \
    && cp /usr/local/go/LICENSE /out/licenses/go.txt \
    && cp /go/pkg/mod/github.com/moby/sys/user@v0.4.1/LICENSE /out/licenses/moby-user.txt \
    && cp /go/pkg/mod/golang.org/x/sys@v0.1.0/LICENSE /out/licenses/x-sys.txt

FROM postgres:17@sha256:0d938e975177bef9f1f61f3f9dbcf2e0aa0a139568f66d3415ed8892eb97f6ac
RUN set -eu; \
    version="$(postgres --version)"; \
    test "$version" = 'postgres (PostgreSQL) 17.12 (Debian 17.12-1.pgdg13+1)'; \
    apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade \
       apt libgssapi-krb5-2 libk5crypto3 libkrb5-3 libkrb5support0 libzstd1 zstd zlib1g \
    && for requirement in 'apt=3.0.4' 'libgssapi-krb5-2=1.21.3-5+deb13u2' 'libk5crypto3=1.21.3-5+deb13u2' \
       'libkrb5-3=1.21.3-5+deb13u2' 'libkrb5support0=1.21.3-5+deb13u2' \
       'libzstd1=1.5.7+dfsg-1+deb13u1' 'zstd=1.5.7+dfsg-1+deb13u1' \
       'zlib1g=1:1.3.dfsg+really1.3.1-1+deb13u1'; do \
         actual="$(dpkg-query -W -f='${Version}' "${requirement%%=*}")" \
             && dpkg --compare-versions "$actual" ge "${requirement#*=}" || exit 1; \
       done \
    && apt-get install -y --no-install-recommends 'pgbackrest=2.60.1-1.pgdg13+1' \
    && test "$(postgres --version)" = "$version" \
    && pgbackrest version | grep -F 'pgBackRest 2.60.1' \
    && rm -rf /var/lib/apt/lists/*
COPY --from=gosu --chmod=755 /out/gosu /usr/local/bin/gosu
COPY --from=gosu /out/licenses/ /usr/local/share/licenses/kernscale-gosu/
