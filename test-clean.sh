#!/bin/bash

# Script para ejecutar tests sin warnings de deprecación
# Uso: ./test-clean.sh [opciones de phpunit]
#
# Ejemplos:
#   ./test-clean.sh --filter=GameMatchModelTest
#   ./test-clean.sh --testsuite=Feature
#   ./test-clean.sh

php artisan test "$@" 2>&1 | grep -v "WARN  Metadata found" | grep -v "Metadata in doc-comments" | grep -v "deprecated and will no longer"
