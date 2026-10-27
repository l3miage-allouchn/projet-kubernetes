{{/* Nom de base des objets de l'application : le nom de la release */}}
{{- define "tomtroc.fullname" -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/* Étiquette de l'image : tag des valeurs, sinon appVersion */}}
{{- define "tomtroc.imageTag" -}}
{{- .Values.image.tag | default .Chart.AppVersion -}}
{{- end -}}

{{/* Labels communs à tous les objets */}}
{{- define "tomtroc.commonLabels" -}}
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/part-of: tomtroc
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{/* Labels des objets de l'application web */}}
{{- define "tomtroc.labels" -}}
{{ include "tomtroc.selectorLabels" . }}
app.kubernetes.io/version: {{ include "tomtroc.imageTag" . | quote }}
{{ include "tomtroc.commonLabels" . }}
{{- end -}}

{{/*
Labels de sélection des pods de l'application. Le sélecteur d'un Deployment ne peut
plus changer après sa création : on n'y met que le nom.
*/}}
{{- define "tomtroc.selectorLabels" -}}
app.kubernetes.io/name: tomtroc
{{- end -}}

{{/*
Adresse de la base pour l'application : avec Galera, le Service "-primary" envoie
toutes les écritures au même nœud ; sans Galera, le Service porte le nom du serveur.
*/}}
{{- define "tomtroc.dbHost" -}}
{{- if .Values.mariadb.galera.enabled -}}
{{ .Values.mariadb.name }}-primary
{{- else -}}
{{ .Values.mariadb.name }}
{{- end -}}
{{- end -}}
