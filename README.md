# notes

ssh -L 0.0.0.0:9000:localhost:9000 -L 0.0.0.0:9001:localhost:9001 val-workoflow-prod
ssh -L 0.0.0.0:3307:localhost:3306 val-workoflow-stage

Plan:
 - api change, the tool can request adding a api key or connecting a integration (signed url for requested user attached to the org - auto user creation)
 - oauth2 login urls
 - rights & roles
 - multi org compatibility
