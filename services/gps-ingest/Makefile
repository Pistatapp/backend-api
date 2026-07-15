.PHONY: build test bench run tidy

build:
	go build -o bin/gps-ingest ./cmd/server

test:
	go test ./...

bench:
	go test -bench=. -benchmem ./internal/http

run:
	go run ./cmd/server

tidy:
	go mod tidy
