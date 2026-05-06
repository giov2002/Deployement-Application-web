pipeline {
    agent any

    stages {

        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Build Images') {
            steps {
                sh 'docker build -t backend:latest ./backend'
                sh 'docker build -t frontend:latest ./frontend'
            }
        }

        stage('Deploy K8s') {
            steps {
                sh 'kubectl apply -f k8s/namespace.yaml'
                sh 'kubectl apply -f k8s/secrets.yaml'
                sh 'kubectl apply -f k8s/configmap.yaml'
                sh 'kubectl apply -f k8s/postgres/postgres.yaml'
                sh 'kubectl apply -f k8s/backend/backend.yaml'
                sh 'kubectl apply -f k8s/frontend/frontend.yaml'
                sh 'kubectl apply -f k8s/ingress.yaml'
                sh 'kubectl rollout restart deployment/backend -n devops-app'
                sh 'kubectl rollout restart deployment/frontend -n devops-app'
            }
        }

        stage('Migrate') {
            steps {
                sh 'sleep 20'
                sh 'kubectl exec -n devops-app deployment/backend -c php-fpm -- php artisan migrate --force'
            }
        }
    }

    post {
        success {
            echo 'Deploiement reussi !'
        }
        failure {
            echo 'Echec du pipeline !'
        }
    }
}