# cfn-lint over the templates of the CDK app (infra/cdk/cdk.out) and of the serverless service of the functions
# (infra/functions/.package), mounted read-only at /templates: the schemas of the resources ship with it, so
# it runs without a network (make cdk-check).
FROM python:3.13-alpine

COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir --disable-pip-version-check --requirement /tmp/requirements.txt && rm /tmp/requirements.txt

ENV HOME=/tmp \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /templates

ENTRYPOINT ["cfn-lint"]
CMD ["--format", "parseable", "--", "cdk/assembly-local/*.template.json", "cdk/assembly-prod/*.template.json", "functions/local/cloudformation-template-update-stack.json", "functions/prod/cloudformation-template-update-stack.json", "functions/prod-unreserved/cloudformation-template-update-stack.json"]
