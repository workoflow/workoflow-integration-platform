Please check @docs/agents/system_prompts/sharepoint_agent.xml and  
@docs/agents/system_prompts/README.md

its like the sharepoint agent is not searching correct or our integration / code is not working
properly. Please help me and ultrathink

The user prompt was: Suche nach Dokumenten im SharePoint mit den Stichworten
\"Urlaubsregelung\", \"Urlaub\", \"Urlaubsantrag\", \"Urlaubsanspruch\", \"Urlaubstage\" oder
\"Vacation\" im Dateinamen und in den Inhalten. Gib die Ergebnisse an und frage gezielt nach
Genehmigung, bevor Dokumente gelesen werden.

POST https://subscribe-workflows.vcec.cloud/api/integrations/ae6f26a3-6f27-4ed6-a3a8-800c3226fb7
9/execute?workflow_user_id=45908692-019e-4436-810c-b417f58f5f4f
[
{
"toolId": "sharepoint_search_documents_2",
"parameters": {
"query": "Urlaubsregelung, Urlaub, Urlaubsantrag, Urlaubsanspruch, Urlaubstage, Vacation,
filename:Urlaubsregelung, filename:Urlaub, filename:Urlaubsantrag, filename:Urlaubsanspruch,
filename:Urlaubstage, filename:Vacation"
}
}
]

Response:
[
{
"response": [
{
"success": true,
"result": {
"value": [],
"count": 0,
"original_count": 5,
"filename_filter_applied": true,
"filename_patterns": [
"Urlaubsregelung",
"Urlaub",
"Urlaubsantrag",
"Urlaubsanspruch",
"Urlaubstage",
"Vacation"
]
}
}
]
}
]

But here you see, a lot of results are existing [Image #1]
(https://valanticmore-my.sharepoint.com/query?q=Urlaub&searchScope=all).

so ultrathink and tell me whats wrong in our system prompt or implementation by also
understanding the payloads 
