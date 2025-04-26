jQuery(function($){
    let lastLeads = [];

    // Hide upgrade section initially
    function initForm() {
        $('.ll-upgrade-section').hide();
    }

    // Render table of leads
    function renderTable(leads) {
        lastLeads = leads;
        let html = `
            <table class="locallead-table">
                <thead>
                    <tr>
                        <th>Name (Click - See Website)</th>
                        <th>Address</th>
                        <th>Contact</th>
                        <th>Email</th>
                        <th>Phone</th>
                    </tr>
                </thead>
                <tbody>
        `;
        leads.forEach(function(lead){
            const nameCell = lead.website
                ? `<a href="${lead.website}" target="_blank">${lead.name}</a>`
                : lead.name;

            let contactLink = '';
            if(lead.website){
                try{
                    const urlObj = new URL(lead.website);
                    contactLink = urlObj.origin + '/contact';
                }catch(err){ }
            }
            const contactCell = contactLink
                ? `<a href="${contactLink}" target="_blank">Contact</a>`
                : '';

            const emailCell = lead.email
                ? `<a href="mailto:${lead.email}">${lead.email}</a>`
                : '';

            html += `
                <tr>
                    <td>${nameCell}</td>
                    <td>${lead.address}</td>
                    <td>${contactCell}</td>
                    <td>${emailCell}</td>
                    <td>${lead.phone || ''}</td>
                </tr>
            `;
        });
        html += `
                </tbody>
            </table>
        `;
        $('#ll-results').html(html);
        // Show upgrade section if allowed
        if(LocalLeadAI.can_email_all) {
            $('.ll-upgrade-section').show();
            $('#ll-export-btn').show();
        }
    }

    // Download CSV
    function downloadCSV(leads) {
        console.log('Generating CSV with ' + leads.length + ' leads');
        let csv = 'Name,Address,Contact Page,Email,Phone\n';
        leads.forEach(l => {
            csv += `"${(l.name || '').replace(/"/g, '""')}","${(l.address || '').replace(/"/g, '""')}","${(l.contact || '').replace(/"/g, '""')}","${(l.email || '').replace(/"/g, '""')}","${(l.phone || '').replace(/"/g, '""')}"\n`;
        });
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'biz_leads_local_results.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // Email all results
    function emailResults(location, industry) {
        $.post(LocalLeadAI.ajax_url, {
            action: 'locallead_ai_email_results',
            location: location,
            industry: industry
        }).done(function(res){
            alert(res.success ? res.data : 'Error: ' + res.data);
        }).fail(function(){
            alert('Unexpected error sending leads.');
        });
    }

    // Handle form submit
    $('#ll-submit').on('click', function(e){
        e.preventDefault();
        const loc = $('#ll-location').val().trim();
        const ind = $('#ll-industry').val().trim();
        $('#ll-results').html('<p>Searching for leads…</p>');
        initForm();
        $.post(LocalLeadAI.ajax_url, {
            action: 'locallead_ai_get_leads',
            location: loc,
            industry: ind
        }).done(function(res){
            console.log('Lead search response:', res);
            if(res.success && Array.isArray(res.data)){
                renderTable(res.data);
            } else {
                $('#ll-results').html(`<p>${res.data || 'Error fetching leads'}</p>`);
            }
        }).fail(function(jqXHR, textStatus, errorThrown){
            console.error('Lead search AJAX error:', textStatus, errorThrown, jqXHR.responseText);
            $('#ll-results').html('<p>Unexpected error fetching leads.</p>');
        });
    });

    // Toggle email button
    $('#ll-email-all').on('change', function(){
        $('#ll-email-btn').toggle(this.checked);
    });

    // Export CSV click
    $('#ll-export-btn').on('click', function(){
        const loc = $('#ll-location').val().trim();
        const ind = $('#ll-industry').val().trim();
        if (!loc || !ind) {
            alert('Please enter a location and industry before downloading CSV.');
            return;
        }
        console.log('Requesting CSV download: action=locallead_ai_download_csv, location=' + loc + ', industry=' + ind + ', endpoint=' + LocalLeadAI.ajax_url);
        $.ajax({
            url: LocalLeadAI.ajax_url,
            type: 'POST',
            data: {
                action: 'locallead_ai_download_csv',
                location: loc,
                industry: ind,
                _v: Date.now()
            },
            timeout: 30000,
            success: function(res){
                console.log('CSV download response:', res);
                if(res.success && Array.isArray(res.data)){
                    console.log('CSV leads count: ' + res.data.length);
                    downloadCSV(res.data);
                } else {
                    console.error('Invalid CSV response:', res);
                    alert('Error downloading CSV: ' + (res.data || 'No leads returned'));
                }
            },
            error: function(jqXHR, textStatus, errorThrown){
                console.error('CSV download AJAX error:', textStatus, errorThrown, jqXHR.responseText);
                alert('Failed to download CSV: ' + textStatus + '. Check console for details.');
            }
        });
    });

    // Email leads click
    $('#ll-email-btn').on('click', function(){
        const loc = $('#ll-location').val().trim();
        const ind = $('#ll-industry').val().trim();
        emailResults(loc, ind);
    });

    // Initialize on load
    initForm();
});