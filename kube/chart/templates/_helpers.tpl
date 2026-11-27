{{- define "electrical-api.env" -}}
- name: PHP_IDE_CONFIG
  value: serverName={{.Values.serviceName}}
{{- if .Values.postgres.enabled }}
- name: DATABASE_URL
  value: {{ printf "postgresql://%s:%s@%s-db:5432/%s?serverVersion=18&charset=utf8" .Values.postgres.user .Values.postgres.password .Values.serviceName .Values.postgres.dbName | quote }}
{{- end }}
- name: CORS_ALLOW_ORIGIN
  value: {{.Values.corsAllowOrigin | quote}}
- name: TRUSTED_PROXIES
  value: {{.Values.trustedProxies | quote}}
{{- if .Values.jwtSecretName }}
- name: JWT_PASSPHRASE
  valueFrom:
    secretKeyRef:
      name: {{.Values.jwtSecretName}}
      key: passphrase
{{- end }}
{{- if .Values.otpSecretName }}
- name: OTP_HMAC_KEY
  valueFrom:
    secretKeyRef:
      name: {{.Values.otpSecretName}}
      key: OTP_HMAC_KEY
{{- end }}
{{- end }}
